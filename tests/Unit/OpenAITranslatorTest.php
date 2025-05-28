<?php

use EduLazaro\Laratext\Translators\OpenAITranslator;
use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Tests\TestCase;

class OpenAITranslatorTest extends TestCase
{
    /** @test */
    public function it_translates_text_to_multiple_languages()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'es' => 'Hola mundo',
                                'fr' => 'Bonjour le monde',
                            ])
                        ]
                    ],
                ],
            ], 200)
        ]);

        $translator = new OpenAITranslator();

        $result = $translator->translate('Hello world', 'en', ['es', 'fr']);

        $this->assertEquals([
            'es' => 'Hola mundo',
            'fr' => 'Bonjour le monde',
        ], $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions';
        });
    }

    /** @test */
    public function it_translates_many_texts_to_multiple_languages()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'key1' => [
                                    'es' => 'Hola',
                                    'fr' => 'Salut',
                                ],
                                'key2' => [
                                    'es' => 'Mundo',
                                    'fr' => 'Monde',
                                ],
                            ]),
                        ],
                    ],
                ],
            ], 200)
        ]);

        $translator = new OpenAITranslator();

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

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions';
        });
    }
}
