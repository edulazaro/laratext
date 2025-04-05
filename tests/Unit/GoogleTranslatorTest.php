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
}
