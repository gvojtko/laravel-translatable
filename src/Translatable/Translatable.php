<?php

namespace Dimsav\Translatable;

use App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Dimsav\Translatable\Exception\LocalesNotDefinedException;

trait Translatable
{
    protected $defaultLocale;

    /**
     * Alias for getTranslation().
     *
     * @param LanguageModel|null $language
     * @param bool        $withFallback
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function translate(LanguageModel $language = null, $withFallback = false)
    {
        return $this->getTranslation($language, $withFallback);
    }

    /**
     * Alias for getTranslation().
     *
     * @param LanguageModel|null $language
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function translateOrDefault(LanguageModel $language)
    {
        return $this->getTranslation($language, true);
    }

    /**
     * Alias for getTranslationOrNew().
     *
     * @param LanguageModel|null $language
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function translateOrNew(LanguageModel $language)
    {
        return $this->getTranslationOrNew($language);
    }

    /**
     * @param LanguageModel|null $language
     * @param bool        $withFallback
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getTranslation(LanguageModel $language = null, $withFallback = null)
    {
        $configFallbackLocale = $this->getFallbackLocale();
        $language = $language ?: $this->localeLanguage();
        $withFallback = $withFallback === null ? $this->useFallback() : $withFallback;
        $fallbackLocale = $this->getFallbackLocale($language);

        if ($translation = $this->getTranslationByLanguage($language)) {
            return $translation;
        }
        if ($withFallback && $fallbackLocale) {
            if ($translation = $this->getTranslationByLanguage($fallbackLocale)) {
                return $translation;
            }
            if ($translation = $this->getTranslationByLanguage($configFallbackLocale)) {
                return $translation;
            }
        }

        return null;
    }

    /**
     * @param LanguageModel|null $language
     *
     * @return bool
     */
    public function hasTranslation(LanguageModel $language = null)
    {
        $language = $language ?: $this->localeLanguage();

        foreach ($this->translations as $translation) {
            if ($translation->getAttribute($this->getLocaleKey()) == $language) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function getTranslationModelName()
    {
        return $this->translationModel ?: $this->getTranslationModelNameDefault();
    }

    /**
     * @return string
     */
    public function getTranslationModelNameDefault()
    {
        $config = app()->make('config');

        return get_class($this).$config->get('translatable.translation_suffix', 'Translation');
    }

    /**
     * @return string
     */
    public function getRelationKey()
    {
        if ($this->translationForeignKey) {
            $key = $this->translationForeignKey;
        } elseif ($this->primaryKey !== 'id') {
            $key = $this->primaryKey;
        } else {
            $key = $this->getForeignKey();
        }

        return $key;
    }

    /**
     * @return string
     */
    public function getLocaleKey()
    {
        $config = app()->make('config');

        return $this->localeKey ?: $config->get('translatable.locale_key', 'locale');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function translations()
    {
        return $this->hasMany($this->getTranslationModelName(), $this->getRelationKey());
    }

    /**
     * @return bool
     */
    private function usePropertyFallback()
    {
        return app()->make('config')->get('translatable.use_property_fallback', false);
    }

    /**
     * Returns the attribute value from fallback translation if value of attribute
     * is empty and the property fallback is enabled in the configuration.
     * in model.
     * @param LanguageModel $language
     * @param $attribute
     * @return mixed
     */
    private function getAttributeOrFallback(LanguageModel $language, $attribute)
    {
        $value = $this->getTranslation($language)->$attribute;

        $usePropertyFallback = $this->useFallback() && $this->usePropertyFallback();
        if (empty($value) && $usePropertyFallback) {
            return $this->getTranslation($this->getFallbackLocale(), true)->$attribute;
        }

        return $value;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getAttribute($key)
    {
        list($attribute, $language) = $this->getAttributeAndLocale($key);

        if ($this->isTranslationAttribute($attribute)) {
            if ($this->getTranslation($language) === null) {
                return null;
            }

            // If the given $attribute has a mutator, we push it to $attributes and then call getAttributeValue
            // on it. This way, we can use Eloquent's checking for Mutation, type casting, and
            // Date fields.
            if ($this->hasGetMutator($attribute)) {
                $this->attributes[$attribute] = $this->getAttributeOrFallback($language, $attribute);

                return $this->getAttributeValue($attribute);
            }

            return $this->getAttributeOrFallback($language, $attribute);
        }

        return parent::getAttribute($key);
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        list($attribute, $language) = $this->getAttributeAndLocale($key);

        if ($this->isTranslationAttribute($attribute)) {
            $this->getTranslationOrNew($language)->$attribute = $value;
        } else {
            return parent::setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * @param array $options
     *
     * @return bool
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            if (count($this->getDirty()) > 0) {
                // If $this->exists and dirty, parent::save() has to return true. If not,
                // an error has occurred. Therefore we shouldn't save the translations.
                if (parent::save($options)) {
                    return $this->saveTranslations();
                }

                return false;
            } else {
                // If $this->exists and not dirty, parent::save() skips saving and returns
                // false. So we have to save the translations
                if ($saved = $this->saveTranslations()) {
                    $this->fireModelEvent('saved', false);
                    $this->fireModelEvent('updated', false);
                }

                return $saved;
            }
        } elseif (parent::save($options)) {
            // We save the translations only if the instance is saved in the database.
            return $this->saveTranslations();
        }

        return false;
    }

    /**
     * @param string LanguageModel $language
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    protected function getTranslationOrNew(LanguageModel $language)
    {
        if (($translation = $this->getTranslation($language, false)) === null) {
            $translation = $this->getNewTranslation($language);
        }

        return $translation;
    }

    /**
     * @param array $attributes
     *
     * @throws \Illuminate\Database\Eloquent\MassAssignmentException
     * @return $this
     */
    public function fill(array $attributes)
    {
        foreach ($attributes as $key => $values) {
            if ($this->isKeyALocale($key)) {
                $this->getTranslationOrNew($key)->fill($values);
                unset($attributes[$key]);
            } else {
                list($attribute, $language) = $this->getAttributeAndLocale($key);
                if ($this->isTranslationAttribute($attribute) and $this->isKeyALocale($language)) {
                    $this->getTranslationOrNew($language)->fill([$attribute => $values]);
                    unset($attributes[$key]);
                }
            }
        }

        return parent::fill($attributes);
    }

    /**
     * @param string $key
     */
    private function getTranslationByLanguage($key)
    {
        foreach ($this->translations as $translation) {
            if ($translation->getAttribute($this->getLocaleKey()) == $key) {
                return $translation;
            }
        }

        return null;
    }

    /**
     * @param null LanguageModel|null $language
     *
     * @return string
     */
    private function getFallbackLocale(LanguageModel $language = null)
    {
        if ($language && $this->isLocaleCountryBased($language)) {
            if ($fallback = $this->getLanguageFromCountryBasedLocale($language)) {
                return $fallback;
            }
        }

        return app()->make('config')->get('translatable.fallback_locale');
    }

    /**
     * @param LanguageModel $language
     *
     * @return bool
     */
    private function isLocaleCountryBased($language)
    {
        return strpos($language, $this->getLocaleSeparator()) !== false;
    }

    /**
     * @param LanguageModel $language
     *
     * @return string
     */
    private function getLanguageFromCountryBasedLocale(LanguageModel $language)
    {
        $parts = explode($this->getLocaleSeparator(), $language);

        return array_get($parts, 0);
    }

    /**
     * @return bool|null
     */
    private function useFallback()
    {
        if (isset($this->useTranslationFallback) && $this->useTranslationFallback !== null) {
            return $this->useTranslationFallback;
        }

        return app()->make('config')->get('translatable.use_fallback');
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function isTranslationAttribute($key)
    {
        return in_array($key, $this->translatedAttributes);
    }

    /**
     * @param string $key
     *
     * @throws \Dimsav\Translatable\Exception\LocalesNotDefinedException
     * @return bool
     */
    protected function isKeyALocale($key)
    {
        $languages = $this->getLocales();

        return in_array($key, $languages);
    }

    /**
     * @throws \Dimsav\Translatable\Exception\LocalesNotDefinedException
     * @return array
     */
    protected function getLocales()
    {
        $languagesConfig = (array) app()->make('config')->get('translatable.locales');

        if (empty($languagessConfig)) {
            throw new LocalesNotDefinedException('Please make sure you have run "php artisan config:publish dimsav/laravel-translatable" '.
                ' and that the locales configuration is defined.');
        }

        $languages = [];
        foreach ($languagesConfig as $key => $language) {
            if (is_array($language)) {
                $languages[] = $key;
                foreach ($language as $countryLanguage) {
                    $languages[] = $key.$this->getLocaleSeparator().$countryLanguage;
                }
            } else {
                $languages[] = $language;
            }
        }

        return $languages;
    }

    /**
     * @return string
     */
    protected function getLocaleSeparator()
    {
        return app()->make('config')->get('translatable.locale_separator', '-');
    }

    /**
     * @return bool
     */
    protected function saveTranslations()
    {
        $saved = true;
        foreach ($this->translations as $translation) {
            if ($saved && $this->isTranslationDirty($translation)) {
                if (! empty($connectionName = $this->getConnectionName())) {
                    $translation->setConnection($connectionName);
                }

                $translation->setAttribute($this->getRelationKey(), $this->getKey());
                $saved = $translation->save();
            }
        }

        return $saved;
    }

    /**
     * @param array
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function replicateWithTranslations(array $except = null)
    {
        $newInstance = parent::replicate($except);

        unset($newInstance->translations);
        foreach ($this->translations as $translation) {
            $newTranslation = $translation->replicate();
            $newInstance->translations->add($newTranslation);
        }

        return  $newInstance;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $translation
     *
     * @return bool
     */
    protected function isTranslationDirty(Model $translation)
    {
        $dirtyAttributes = $translation->getDirty();
        unset($dirtyAttributes[$this->getLocaleKey()]);

        return count($dirtyAttributes) > 0;
    }

    /**
     * @param string LanguageModel $language
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getNewTranslation(LanguageModel $language)
    {
        $modelName = $this->getTranslationModelName();
        $translation = new $modelName();
        $translation->setAttribute($this->getLocaleKey(), $language);
        $this->translations->add($translation);

        return $translation;
    }

    /**
     * @param $key
     *
     * @return bool
     */
    public function __isset($key)
    {
        return $this->isTranslationAttribute($key) || parent::__isset($key);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param LanguageModel|null $language
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeTranslatedIn(Builder $query, LanguageModel $language = null)
    {
        $language = $language ?: $this->localeLanguage();

        return $query->whereHas('translations', function (Builder $q) use ($language) {
            $q->where($this->getLocaleKey(), '=', $language);
        });
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param LanguageModel|null $language
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeNotTranslatedIn(Builder $query, LanguageModel $language = null)
    {
        $language = $language ?: $this->localeLanguage();

        return $query->whereDoesntHave('translations', function (Builder $q) use ($language) {
            $q->where($this->getLocaleKey(), '=', $language);
        });
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeTranslated(Builder $query)
    {
        return $query->has('translations');
    }

    /**
     * Adds scope to get a list of translated attributes, using the current locale.
     * Example usage: Country::listsTranslations('name')->get()->toArray()
     * Will return an array with items:
     *  [
     *      'id' => '1',                // The id of country
     *      'name' => 'Griechenland'    // The translated name
     *  ].
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $translationField
     */
    public function scopeListsTranslations(Builder $query, $translationField)
    {
        $withFallback = $this->useFallback();
        $translationTable = $this->getTranslationsTable();
        $localeKey = $this->getLocaleKey();

        $query
            ->select($this->getTable().'.'.$this->getKeyName(), $translationTable.'.'.$translationField)
            ->leftJoin($translationTable, $translationTable.'.'.$this->getRelationKey(), '=', $this->getTable().'.'.$this->getKeyName())
            ->where($translationTable.'.'.$localeKey, $this->localeLanguage());
        if ($withFallback) {
            $query->orWhere(function (Builder $q) use ($translationTable, $localeKey) {
                $q->where($translationTable.'.'.$localeKey, $this->getFallbackLocale())
                  ->whereNotIn($translationTable.'.'.$this->getRelationKey(), function (QueryBuilder $q) use (
                      $translationTable,
                      $localeKey
                  ) {
                      $q->select($translationTable.'.'.$this->getRelationKey())
                        ->from($translationTable)
                        ->where($translationTable.'.'.$localeKey, $this->localeLanguage());
                  });
            });
        }
    }

    /**
     * This scope eager loads the translations for the default and the fallback locale only.
     * We can use this as a shortcut to improve performance in our application.
     *
     * @param Builder $query
     */
    public function scopeWithTranslation(Builder $query)
    {
        $query->with([
            'translations' => function (Relation $query) {
                $query->where($this->getTranslationsTable().'.'.$this->getLocaleKey(), $this->localeLanguage());

                if ($this->useFallback()) {
                    return $query->orWhere($this->getTranslationsTable().'.'.$this->getLocaleKey(), $this->getFallbackLocale());
                }
            },
        ]);
    }

    /**
     * This scope filters results by checking the translation fields.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $key
     * @param string                                $value
     * @param LanguageModel|null $language
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeWhereTranslation(Builder $query, $key, $value, LanguageModel $language = null)
    {
        return $query->whereHas('translations', function (Builder $query) use ($key, $value, $language) {
            $query->where($this->getTranslationsTable().'.'.$key, $value);
            if ($language) {
                $query->where($this->getTranslationsTable().'.'.$this->getLocaleKey(), $language);
            }
        });
    }

    /**
     * This scope filters results by checking the translation fields.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $key
     * @param string                                $value
     * @param LanguageModel|null $language
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeOrWhereTranslation(Builder $query, $key, $value, $language = null)
    {
        return $query->orWhereHas('translations', function (Builder $query) use ($key, $value, $language) {
            $query->where($this->getTranslationsTable().'.'.$key, $value);
            if ($language) {
                $query->where($this->getTranslationsTable().'.'.$this->getLocaleKey(), $language);
            }
        });
    }

    /**
     * This scope filters results by checking the translation fields.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $key
     * @param string                                $value
     * @param LanguageModel|null $language
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeWhereTranslationLike(Builder $query, $key, $value, LanguageModel $language = null)
    {
        return $query->whereHas('translations', function (Builder $query) use ($key, $value, $language) {
            $query->where($this->getTranslationsTable().'.'.$key, 'LIKE', $value);
            if ($language) {
                $query->where($this->getTranslationsTable().'.'.$this->getLocaleKey(), 'LIKE', $language);
            }
        });
    }

    /**
     * This scope filters results by checking the translation fields.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $key
     * @param string                                $value
     * @param LanguageModel|null $language
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeOrWhereTranslationLike(Builder $query, $key, $value, LanguageModel $language = null)
    {
        return $query->orWhereHas('translations', function (Builder $query) use ($key, $value, $language) {
            $query->where($this->getTranslationsTable().'.'.$key, 'LIKE', $value);
            if ($language) {
                $query->where($this->getTranslationsTable().'.'.$this->getLocaleKey(), 'LIKE', $language);
            }
        });
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $attributes = parent::toArray();

        if ($this->relationLoaded('translations') || $this->toArrayAlwaysLoadsTranslations()) {
            // continue
        } else {
            return $attributes;
        }

        $hiddenAttributes = $this->getHidden();

        foreach ($this->translatedAttributes as $field) {
            if (in_array($field, $hiddenAttributes)) {
                continue;
            }

            if ($translations = $this->getTranslation()) {
                $attributes[$field] = $translations->$field;
            }
        }

        return $attributes;
    }

    /**
     * @return array
     */
    public function getTranslationsArray()
    {
        $translations = [];

        foreach ($this->translations as $translation) {
            foreach ($this->translatedAttributes as $attr) {
                $translations[$translation->{$this->getLocaleKey()}][$attr] = $translation->{$attr};
            }
        }

        return $translations;
    }

    /**
     * @return string
     */
    private function getTranslationsTable()
    {
        return app()->make($this->getTranslationModelName())->getTable();
    }

    /**
     * @return LanguageModel|null;
     */
    protected function localeLanguage()
    {
        $locale = $this->locale();
        return $this->getLanguageByCodeKey($locale);
    }
    /**
     * @param string $key
     *
     * @return LanguageModel|null;
     */
    protected function getLanguageByCodeKey($key)
    {
        $languageModel = $this->getLanguageModelName();
        $languageCode  = call_user_func_array([$languageModel, 'getCodeColumnName'], []);
        if ( ! isset($this->relations['languages'])) {
            return call_user_func_array([$languageModel, 'getByCodeKey'], [$key]);
        } else {
            foreach ($this->languages as $language) {
                if ($language[$languageCode] === $key) {
                    return $language;
                }
            }
        }
        return null;
    }

    /**
     * @return LanguageModel $language
     */
    private function getFallbackLanguage()
    {
        $languageModel = $this->getLanguageModelName();
        $fallbackCode  = call_user_func_array([$languageModel, 'getFallbackCode'], []);
        $language      = $this->getLanguageByCodeKey($fallbackCode);
        return $language;
    }

    /**
     * @return mixed
     */
    private function getLanguageModelName()
    {
        return App::make('config')->get('translatable.languages_model');
    }

    /**
     * @return LanguageModel
     */
    private function getNewLanguageModel()
    {
        $class = $this->getLanguageModelName();
        return new $class;
    }

    /**
     * @return string
     */
    protected function locale()
    {
        if ($this->defaultLocale) {
            return $this->defaultLocale;
        }

        return app()->make('config')->get('translatable.locale')
            ?: app()->make('translator')->getLocale();
    }

    /**
     * Set the default locale on the model.
     *
     * @param LanguageModel $language
     *
     * @return $this
     */
    public function setDefaultLocale(LanguageModel $language)
    {
        $this->defaultLocale = $language;

        return $this;
    }

    /**
     * Get the default locale on the model.
     *
     * @return mixed
     */
    public function getDefaultLocale()
    {
        return $this->defaultLocale;
    }

    /**
     * Deletes all translations for this model.
     *
     * @param string|array|null $languages The locales to be deleted (array or single string)
     *                                   (e.g., ["en", "de"] would remove these translations).
     */
    public function deleteTranslations($languages = null)
    {
        if ($languages === null) {
            $translations = $this->translations()->get();
        } else {
            $languages = (array) $languages;
            $translations = $this->translations()->whereIn($this->getLocaleKey(), $languages)->get();
        }
        foreach ($translations as $translation) {
            $translation->delete();
        }

        // we need to manually "reload" the collection built from the relationship
        // otherwise $this->translations()->get() would NOT be the same as $this->translations
        $this->load('translations');
    }

    /**
     * @param $key
     *
     * @return array
     */
    private function getAttributeAndLocale($key)
    {
        if (str_contains($key, ':')) {
            return explode(':', $key);
        }

        return [$key, $this->localeLanguage()];
    }

    /**
     * @return bool
     */
    private function toArrayAlwaysLoadsTranslations()
    {
        return app()->make('config')->get('translatable.to_array_always_loads_translations', true);
    }
}
