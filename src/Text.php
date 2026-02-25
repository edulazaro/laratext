<?php

namespace EduLazaro\Laratext;

class Text
{
    public static function get(string $key, string|null $default = null, array $replace = [], string|null $locale = null): string
    {
        $translation = __($key, $replace, $locale);

        if ($translation === $key || is_array($translation)) {
            $translation = $default ?? $key;

            foreach ($replace as $search => $value) {
                $translation = str_replace(':'.$search, $value, $translation);
            }
        }

        return $translation;
    }
}