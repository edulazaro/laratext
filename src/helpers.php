<?php

use EduLazaro\Laratext\Text;

if (!function_exists('text')) {
    /**
     * Text helper function for translations.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    function text(string $key, string $default = '', array $replace = [], ?string $locale = null): string
    {
        return Text::get($key, $default, $replace, $locale);
    }
}
