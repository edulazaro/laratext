<?php

namespace EduLazaro\Laratext;

use Illuminate\Support\Str;

/**
 * Locked translation keys, stored per language in lang/.locked/{locale}.json.
 *
 * A locked key is never written by the scanner: it is not retranslated when the
 * source text changes and it is not removed by --prune. Nothing is locked
 * unless somebody locks it, so a project with no lock files behaves exactly as
 * it did before this existed.
 */
class TranslationLocks
{
    /**
     * Path of the lock file of a language.
     *
     * @param string $lang
     * @return string
     */
    public static function path(string $lang): string
    {
        return lang_path('.locked/' . $lang . '.json');
    }

    /**
     * Locked keys of a language.
     *
     * @param string $lang
     * @return array<int, string>
     */
    public static function all(string $lang): array
    {
        $path = static::path($lang);

        if (! file_exists($path)) {
            return [];
        }

        $keys = json_decode(file_get_contents($path), true);

        return is_array($keys) ? array_values(array_unique($keys)) : [];
    }

    /**
     * Whether a key is locked in a language. Locked patterns support wildcards,
     * so 'errors.*' covers every key under errors.
     *
     * @param string $lang
     * @param string $key
     * @return bool
     */
    public static function isLocked(string $lang, string $key): bool
    {
        foreach (static::all($lang) as $locked) {
            if ($locked === $key || Str::is($locked, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter a list of keys down to the locked ones.
     *
     * @param string $lang
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    public static function lockedAmong(string $lang, array $keys): array
    {
        return array_values(array_filter($keys, fn ($key) => static::isLocked($lang, $key)));
    }

    /**
     * Lock keys in a language. Returns the keys that were not locked already.
     *
     * @param string $lang
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    public static function lock(string $lang, array $keys): array
    {
        $current = static::all($lang);
        $added = array_values(array_diff($keys, $current));

        if (! empty($added)) {
            static::write($lang, array_merge($current, $added));
        }

        return $added;
    }

    /**
     * Unlock keys in a language. Returns the keys that were actually removed.
     *
     * @param string $lang
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    public static function unlock(string $lang, array $keys): array
    {
        $current = static::all($lang);
        $removed = array_values(array_intersect($current, $keys));

        if (! empty($removed)) {
            static::write($lang, array_values(array_diff($current, $removed)));
        }

        return $removed;
    }

    /**
     * Persist the lock file, removing it when nothing is left so that the
     * absence of the file keeps meaning "nothing is locked".
     *
     * @param string $lang
     * @param array<int, string> $keys
     * @return void
     */
    protected static function write(string $lang, array $keys): void
    {
        $path = static::path($lang);
        $keys = array_values(array_unique($keys));

        sort($keys);

        if (empty($keys)) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }

        $directory = dirname($path);

        is_dir($directory) || mkdir($directory, 0755, true);

        file_put_contents(
            $path,
            json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
    }
}
