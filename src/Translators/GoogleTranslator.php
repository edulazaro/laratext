<?php

namespace EduLazaro\Laratext\Translators;

use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Contracts\TranslatorInterface;
use EduLazaro\Laratext\Translator;

class GoogleTranslator extends Translator implements TranslatorInterface
{
    /**
     * Translates a single string into multiple target languages
     *
     * @param string $text The original text to translate.
     * @param string $from The source language code (e.g., 'en').
     * @param array $to An array of target language codes (e.g., ['es', 'fr']).
     * @return array<string, string> Array of translations indexed by language code.
     */
    public function translate(string $text, string $from, array $to): array
    {
        $results = [];

        foreach ($to as $targetLanguage) {
            $results[$targetLanguage] = $this->translateTo($text, $from, $targetLanguage);
        }

        return $results;
    }

    /**
     * Translates multiple strings into multiple target languages.
     *
     * @param array<string, string> $texts Array of texts with their corresponding keys.
     * @param string $from The source language code (e.g., 'en').
     * @param array $to An array of target language codes (e.g., ['es', 'fr']).
     * @return array<string, array<string, string>> Translations indexed by original key and language code.
     */
    public function translateMany(array $texts, string $from, array $to): array
    {
        $apiKey = config('texts.google.api_key');
        $timeout = config('texts.google.timeout', 10);
        $maxRetries = config('texts.google.retries', 3);

        $results = [];

        foreach ($to as $targetLanguage) {
            // Prepare list of texts
            $textValues = array_values($texts);
            $textKeys = array_keys($texts);

            $response = Http::timeout($timeout)
                ->retry($maxRetries, 10)
                ->post("https://translation.googleapis.com/language/translate/v2", [
                    'q' => $textValues,
                    'source' => $from,
                    'target' => $targetLanguage,
                    'format' => 'text',
                    'key' => $apiKey,
                ]);

            $translated = $response->json('data.translations', []);

            foreach ($translated as $index => $item) {
                $key = $textKeys[$index] ?? null;
                if ($key) {
                    $results[$key][$targetLanguage] = $item['translatedText'] ?? '';
                }
            }
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
