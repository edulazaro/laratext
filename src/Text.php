<?php

namespace EduLazaro\Laratext;

class Text
{
    public static function get(string $key, string|null $default = null, array $replace = [], string|null $locale = null): string
    {
        $translation = __($key, $replace, $locale);

        return $translation !== $key ? $translation : $default ?? $key;
    }
}