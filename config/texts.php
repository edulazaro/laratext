<?php

return [

    'translators' => [
        'openai' => EduLazaro\Laratext\Translators\OpenAITranslator::class,
        'claude' => EduLazaro\Laratext\Translators\ClaudeTranslator::class,
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
        'model' => env('OPENAI_MODEL', 'gpt-5.4-nano'),
        'timeout' => 60,
        'retries' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Claude (Anthropic) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Claude translator. The system prompt is automatically
    | marked with cache_control: ephemeral, so repeated batches in a single
    | scan run benefit from prompt caching at no extra cost.
    |
    */

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),
        'timeout' => 60,
        'retries' => 3,
        'max_tokens' => 4096,
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

    /*
    |--------------------------------------------------------------------------
    | Source Locale
    |--------------------------------------------------------------------------
    |
    | The language the texts in your code are written in, which is what the
    | scanner compares against and what the translator is told to translate
    | from.
    |
    | Leave it null and the application locale is used, which is the same thing
    | in most projects. Set it when they differ, for example an application that
    | runs in Spanish while its source texts are written in English:
    |
    |   text('save', 'Save changes')   with APP_LOCALE=es
    |
    | Without this, every key looks like its source text had changed, since the
    | Spanish translation is compared against the English text in the code.
    |
    */

    'source_locale' => null,

    /*
    |--------------------------------------------------------------------------
    | Application Context
    |--------------------------------------------------------------------------
    |
    | Optional. Sent to the translator along with every batch, so it knows what
    | it is translating instead of guessing from short strings on their own.
    |
    | Use it for what the application does, the register you want, and the
    | terms that must be left alone:
    |
    |   'context' => 'A real estate CRM used by estate agents. Address the user
    |                 formally. Never translate the product names Inmoqueen or
    |                 Laratext.',
    |
    | Translators without a prompt, such as Google Translate, ignore it.
    |
    */

    'context' => '',
];
