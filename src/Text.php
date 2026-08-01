<?php

namespace EduLazaro\Laratext;

use Illuminate\Support\Facades\App;
use Illuminate\Translation\MessageSelector;

class Text
{
    public static function get(string $key, string|null $default = null, array $replace = [], string|null $locale = null): string
    {
        $translation = __($key, $replace, $locale);

        if ($translation !== $key && ! is_array($translation)) {
            // The key exists in the language file. When its text carries plural
            // forms, __() has already replaced the placeholders but not chosen
            // a form, so the choice is made here.
            return static::hasPluralForms($key, $replace, $locale)
                ? trans_choice($key, $replace['count'], $replace, $locale)
                : $translation;
        }

        $translation = $default ?? $key;

        // The key is not in the language file yet, so the source text is used.
        // Plural forms are honoured here too, otherwise a brand new key would
        // render every form at once until the next scan.
        if (static::isPluralized($translation, $replace)) {
            $translation = static::selector()->choose(
                $translation,
                (int) $replace['count'],
                $locale ?: App::getLocale()
            );
        }

        foreach ($replace as $search => $value) {
            $translation = str_replace(':' . $search, $value, $translation);
        }

        return $translation;
    }

    /**
     * Whether a text is written with plural forms and was given a quantity.
     *
     * Both signals are required. Laravel only treats a pipe as a separator
     * inside trans_choice, so a text that merely contains one, such as
     * "Ctrl|Alt", must keep being returned untouched.
     *
     * @param string $text
     * @param array $replace
     * @return bool
     */
    protected static function isPluralized(string $text, array $replace): bool
    {
        return str_contains($text, '|')
            && isset($replace['count'])
            && is_numeric($replace['count']);
    }

    /**
     * Whether the stored translation of a key has plural forms.
     *
     * @param string $key
     * @param array $replace
     * @param string|null $locale
     * @return bool
     */
    protected static function hasPluralForms(string $key, array $replace, string|null $locale): bool
    {
        if (! isset($replace['count']) || ! is_numeric($replace['count'])) {
            return false;
        }

        // Read the raw line, without replacements, so a replaced value that
        // happens to contain a pipe is not mistaken for a plural form.
        $raw = trans($key, [], $locale);

        return is_string($raw) && str_contains($raw, '|');
    }

    /**
     * @return MessageSelector
     */
    protected static function selector(): MessageSelector
    {
        return new MessageSelector;
    }
}
