<?php

namespace EduLazaro\Laratext\Translators;

use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Contracts\TranslatorInterface;
use EduLazaro\Laratext\Translator;
use RuntimeException;

class OpenAITranslator extends Translator implements TranslatorInterface
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
        $apiKey = config('texts.openai.api_key');
        $model = config('texts.openai.model', 'gpt-3.5-turbo');
        $timeout = config('texts.openai.timeout', 60);
        $maxRetries = config('texts.openai.retries', 3);

        // Build instructions for multiple languages
        $languagesList = implode(', ', $to);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])
            ->timeout($timeout)
            ->retry($maxRetries, 1000)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are a helpful assistant that translates from {$from} into multiple languages: {$languagesList}. Reply with a JSON object, where each property is the language code and the value is the translated text. Preserve placeholders like :name, :count, or any text wrapped in colons (:) exactly as they are.",
                    ],
                    [
                        'role' => 'user',
                        'content' => $text,
                    ],
                ],
                'temperature' => 0,
            ]);

        $rawContent = trim($response->json('choices.0.message.content', '{}'));

        // Remove markdown code block markers if present
        $rawContent = preg_replace('/^```json\s*/', '', $rawContent);
        $rawContent = preg_replace('/\s*```$/', '', $rawContent);
        $rawContent = trim($rawContent);

        // Attempt to decode JSON
        $translations = json_decode($rawContent, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($translations)) {
            throw new RuntimeException("Failed to decode translation response: " . $rawContent);
        }

        return $translations;
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
        $apiKey = config('texts.openai.api_key');
        $model = config('texts.openai.model', 'gpt-3.5-turbo');
        $timeout = config('texts.openai.timeout', 10);
        $maxRetries = config('texts.openai.retries', 3);

        $languagesList = implode(', ', $to);

        $inputJson = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $count = count($texts);
        $this->logToConsole("➡️ Sending {$count} texts to OpenAI for translation into [{$languagesList}] using model [{$model}]...");

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])
            ->timeout($timeout)
            ->retry($maxRetries, 1000)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are a helpful assistant that translates JSON key-value pairs from {$from} into multiple languages: {$languagesList}. Reply with a JSON object where each key from the input maps to an object of translations per language. Preserve any placeholder like :name, :count, or any text wrapped in colons (:).",
                    ],
                    [
                        'role' => 'user',
                        'content' => $inputJson,
                    ],
                ],
                'temperature' => 0,
            ]);

        $rawContent = trim($response->json('choices.0.message.content', '{}'));

        // Remove markdown code block markers if present
        $rawContent = preg_replace('/^```json\s*/', '', $rawContent);
        $rawContent = preg_replace('/\s*```$/', '', $rawContent);
        $rawContent = trim($rawContent);

        $translations = json_decode($rawContent, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($translations)) {
            throw new RuntimeException("Failed to decode batch translation response: " . $rawContent);
        }

        $translatedKeys = implode(', ', array_keys($translations));
        $this->logToConsole("✅ Received translations for: {$translatedKeys}");

        return $translations;
    }
}
