<?php

use EduLazaro\Laratext\Translators\ClaudeTranslator;
use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Tests\TestCase;

class ClaudeTranslatorTest extends TestCase
{
    /** @test */
    public function it_translates_text_to_multiple_languages()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'es' => 'Hola mundo',
                            'fr' => 'Bonjour le monde',
                        ]),
                    ],
                ],
            ], 200),
        ]);

        $translator = new ClaudeTranslator();

        $result = $translator->translate('Hello world', 'en', ['es', 'fr']);

        $this->assertEquals([
            'es' => 'Hola mundo',
            'fr' => 'Bonjour le monde',
        ], $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key')
                && $request->hasHeader('anthropic-version', '2023-06-01');
        });
    }

    /** @test */
    public function it_translates_many_texts_to_multiple_languages()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
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
            ], 200),
        ]);

        $translator = new ClaudeTranslator();

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
            return $request->url() === 'https://api.anthropic.com/v1/messages';
        });
    }

    /** @test */
    public function it_marks_system_prompt_for_caching()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => json_encode(['es' => 'Hola'])],
                ],
            ], 200),
        ]);

        $translator = new ClaudeTranslator();
        $translator->translate('Hello', 'en', ['es']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return is_array($body['system'] ?? null)
                && ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral';
        });
    }

    /** @test */
    public function it_strips_markdown_code_fences_from_response()
    {
        // Even when asked not to, Claude occasionally wraps JSON in ```json ... ```
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "```json\n" . json_encode(['es' => 'Hola']) . "\n```",
                    ],
                ],
            ], 200),
        ]);

        $translator = new ClaudeTranslator();

        $result = $translator->translate('Hello', 'en', ['es']);

        $this->assertEquals(['es' => 'Hola'], $result);
    }
}
