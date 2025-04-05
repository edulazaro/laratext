<?php

namespace EduLazaro\Laratext\Translators;

use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Contracts\TranslatorInterface;

class GoogleTranslator implements TranslatorInterface
{
    public function translate(string $text, string $from, array $to): array
    {
        $results = [];

        foreach ($to as $targetLanguage) {
            $results[$targetLanguage] = $this->translateTo($text, $from, $targetLanguage);
        }

        return $results;
    }

    /**
     * Perform the translation request for a single target language.
     *
     * @param string $text
     * @param string $from
     * @param string $to
     * @return string
     */
    protected function translateTo(string $text, string $from, string $to): string
    {
        $apiKey = config('texts.google.api_key');
        $timeout = config('texts.google.timeout', 10);
        $maxRetries = config('texts.google.retries', 3);

        $response = Http::timeout($timeout)
            ->retry($maxRetries, 10)
            ->post("https://translation.googleapis.com/language/translate/v2", [
                'q' => $text,
                'source' => $from,
                'target' => $to,
                'format' => 'text',
                'key' => $apiKey,
            ]);

        return trim($response->json('data.translations.0.translatedText', ''));
    }
}
