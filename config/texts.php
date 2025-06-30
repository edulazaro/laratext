<?php

return [

    'translators' => [
        'openai' => EduLazaro\Laratext\Translators\OpenAITranslator::class,
        'google' => EduLazaro\Laratext\Translators\GoogleTranslator::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Translator
    |--------------------------------------------------------------------------
    |
    | This option controls the default translator to use when running the
    | translation commands. You can later create other translators
    | like DeeplTranslator, GoogleTranslator, etc.
    |
    */

    'default_translator' => 'openai',

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    |
    | Here you can configure the OpenAI translator service, including your
    | API key, preferred model, request timeout, and retry attempts.
    |
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
        'timeout' => 60,
        'retries' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Translator Configuration
    |--------------------------------------------------------------------------
    |
    | Here you can configure the Google Cloud Translation API, including
    | your API key, request timeout, and retry attempts.
    |
    */

    'google' => [
        'api_key' => env('GOOGLE_TRANSLATOR_API_KEY'),
        'timeout' => 20,
        'retries' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Languages
    |--------------------------------------------------------------------------
    |
    | Define your supported languages for translation.
    | The keys are the language codes, and the values are the readable names.
    |
    */

    'languages' => [
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
    ],
];
