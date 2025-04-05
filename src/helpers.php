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
    function text(string $key, string $default = ''): string
    {
        return Text::get($key, $default);
    }
}
