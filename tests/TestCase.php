<?php

namespace EduLazaro\Laratext\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use EduLazaro\Laratext\LaratextServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaratextServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('texts', [
            'default_translator' => \EduLazaro\Laratext\Translators\OpenAITranslator::class,
            'translators' => [
                'openai' => \EduLazaro\Laratext\Translators\OpenAITranslator::class,
                'google' => \EduLazaro\Laratext\Translators\GoogleTranslator::class,
            ],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY', 'fake-openai-api-key'),
                'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
                'timeout' => 10,
                'retries' => 3,
            ],

            'google' => [
                'api_key' => env('GOOGLE_TRANSLATOR_API_KEY', 'fake-google-api-key'),
                'timeout' => 10,
                'retries' => 3,
            ],

            'languages' => [
                'en' => 'English',
                'es' => 'Spanish',
                'fr' => 'French',
            ],
        ]);
    }
}
