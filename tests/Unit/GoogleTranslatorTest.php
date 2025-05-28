<?php

use EduLazaro\Laratext\Translators\GoogleTranslator;
use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Tests\TestCase;

class GoogleTranslatorTest extends TestCase
{
    /** @test */
    public function it_translates_text_to_multiple_languages()
    {
        Http::fake(function ($request) {
            $target = $request['target'];

            $translations = [
                'es' => 'Hola mundo',
                'fr' => 'Bonjour le monde',
            ];

            return Http::response([
                'data' => [
                    'translations' => [
                        ['translatedText' => $translations[$target] ?? 'Unknown'],
                    ],
                ],
            ], 200);
        });

        $translator = new GoogleTranslator();

        $result = $translator->translate('Hello world', 'en', ['es', 'fr']);

        $this->assertEquals([
            'es' => 'Hola mundo',
            'fr' => 'Bonjour le monde',
        ], $result);

        Http::assertSentCount(2); // Optional, to verify 2 calls were made
    }

    /** @test */
    public function it_translates_many_texts_to_multiple_languages()
    {
        Http::fake(function ($request) {
            $target = $request['target'];
            $texts = $request['q'];

            $translations = [
                'es' => [
                    'Hello' => 'Hola',
                    'World' => 'Mundo',
                ],
                'fr' => [
                    'Hello' => 'Salut',
                    'World' => 'Monde',
                ],
            ];

            $response = [
                'data' => [
                    'translations' => array_map(fn($text) => [
                        'translatedText' => $translations[$target][$text] ?? 'Unknown'
                    ], $texts),
                ],
            ];

            return Http::response($response, 200);
        });

        $translator = new GoogleTranslator();

        $texts = [
            'key1' => 'Hello',
            'key2' => 'World',
        ];

        $result = $translator->translateMany($texts, 'en', ['es', 'fr']);

        $this->assertEquals([
            'key1' => [
                'es' => 'Hola',
                'fr' => 'Salut',
            ],
            'key2' => [
                'es' => 'Mundo',
                'fr' => 'Monde',
            ],
        ], $result);

        Http::assertSentCount(2); // One call per language
    }
}
