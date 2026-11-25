<?php

namespace EduLazaro\Laratext;

use BackedEnum;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Translation\MessageSelector;
use UnitEnum;

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

        return static::makeReplacements($translation, $replace);
    }

    /**
     * The translations of the keys under the given prefixes, for JavaScript.
     *
     * Read from the language's JSON file, so a key not translated yet is left
     * out and text() in the browser falls back to its source text, as it does
     * here. '*' takes every key.
     *
     * @param string|array<int, string> $prefixes 'header' takes header and header.*
     * @param string|null $locale
     * @return array{locale: string, lines: array<string, string>}
     */
    public static function frontend(string|array $prefixes, string|null $locale = null): array
    {
        $locale = $locale ?: App::getLocale();
        $prefixes = (array) $prefixes;

        $lines = array_filter(
            (array) app('translator')->getLoader()->load($locale, '*', '*'),
            fn ($line, $key) => is_string($line) && static::matchesPrefix((string) $key, $prefixes),
            ARRAY_FILTER_USE_BOTH
        );

        return ['locale' => $locale, 'lines' => $lines];
    }

    /**
     * The same translations in a JSON script tag, read by text() in the browser.
     *
     * @param string|array<int, string> $prefixes
     * @param string|null $locale
     * @return HtmlString
     */
    public static function script(string|array $prefixes, string|null $locale = null): HtmlString
    {
        $payload = static::frontend($prefixes, $locale);

        // An empty list would print [] where the browser expects an object.
        $payload['lines'] = (object) $payload['lines'];

        // JSON_HEX_TAG keeps a "</script>" inside a translation from closing the tag.
        $json = json_encode($payload, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new HtmlString('<script type="application/json" data-laratext>' . $json . '</script>');
    }

    /**
     * @param string $key
     * @param array<int, string> $prefixes
     * @return bool
     */
    protected static function matchesPrefix(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '*' || $key === $prefix || str_starts_with($key, $prefix . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Placeholders replaced the way Laravel replaces them in a translated line:
     * :name, :Name and :NAME, the longest placeholder first, and a closure
     * wrapping <name>...</name>. A text not in the language file yet gets the
     * same, so it reads the same before and after the next scan.
     *
     * @param string $line
     * @param array $replace
     * @return string
     */
    protected static function makeReplacements(string $line, array $replace): string
    {
        if (empty($replace)) {
            return $line;
        }

        $shouldReplace = [];

        foreach ($replace as $key => $value) {
            if ($value instanceof Closure) {
                $line = preg_replace_callback(
                    '/<' . $key . '>(.*?)<\/' . $key . '>/',
                    fn ($args) => $value($args[1]),
                    $line
                );

                continue;
            }

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof UnitEnum) {
                $value = $value->name;
            }

            $shouldReplace[':' . Str::ucfirst($key)] = Str::ucfirst((string) ($value ?? ''));
            $shouldReplace[':' . Str::upper($key)] = Str::upper((string) ($value ?? ''));
            $shouldReplace[':' . $key] = $value;
        }

        return strtr($line, $shouldReplace);
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
