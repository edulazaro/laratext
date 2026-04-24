<?php

namespace EduLazaro\Laratext\Translators;

use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Contracts\TranslatorInterface;
use EduLazaro\Laratext\Translator;
use RuntimeException;

class ClaudeTranslator extends Translator implements TranslatorInterface
{
    protected const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    protected const API_VERSION = '2023-06-01';

    /**
     * Translates a single string into multiple target languages.
     *
     * @param string $text The original text to translate.
     * @param string $from The source language code (e.g., 'en').
     * @param array $to An array of target language codes (e.g., ['es', 'fr']).
     * @return array<string, string> Array of translations indexed by language code.
     */
    public function translate(string $text, string $from, array $to): array
    {
        $apiKey = config('texts.claude.api_key');
        $model = config('texts.claude.model', 'claude-haiku-4-5');
        $timeout = config('texts.claude.timeout', 60);
        $maxRetries = config('texts.claude.retries', 3);
        $maxTokens = config('texts.claude.max_tokens', 4096);

        $languagesList = implode(', ', $to);

        $systemPrompt = "You are a helpful assistant that translates from {$from} into multiple languages: {$languagesList}. Reply ONLY with a valid JSON object (no markdown, no code blocks, no explanations), where each property is the language code and the value is the translated text. Preserve placeholders like :name, :count, or any text wrapped in colons (:) exactly as they are. IMPORTANT: Do NOT create nested objects. Return a flat JSON object.";

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout($timeout)
            ->retry($maxRetries, 10)
            ->post(self::ENDPOINT, [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => 0,
                'system' => [
                    [
                        'type' => 'text',
                        'text' => $systemPrompt,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $text,
                    ],
                ],
            ]);

        $rawContent = trim($response->json('content.0.text', '{}'));
        $rawContent = $this->stripCodeFences($rawContent);

        $translations = json_decode($rawContent, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($translations)) {
            throw new RuntimeException("Failed to decode translation response: " . $rawContent);
        }

        return $this->flattenArray($translations);
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
        $apiKey = config('texts.claude.api_key');
        $model = config('texts.claude.model', 'claude-haiku-4-5');
        $timeout = config('texts.claude.timeout', 60);
        $maxRetries = config('texts.claude.retries', 3);
        $maxTokens = config('texts.claude.max_tokens', 4096);

        $languagesList = implode(', ', $to);
        $inputJson = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $systemPrompt = "You are a helpful assistant that translates JSON key-value pairs from {$from} into multiple languages: {$languagesList}. Reply ONLY with a valid JSON object (no markdown, no code blocks, no explanations) where each key from the input maps to an object of translations per language. Preserve any placeholder like :name, :count, or any text wrapped in colons (:). CRITICAL: Keep ALL keys EXACTLY as they appear in the input, including dots and numbers (e.g., 'properties.parking_type', 'items.0.name'). Do NOT interpret dots as nested objects. Do NOT create any nested structure. Return keys as-is.";

        $count = count($texts);
        $this->logToConsole("➡️ Sending {$count} texts to Claude for translation into [{$languagesList}] using model [{$model}]...");

        $attempt = 0;
        $translations = null;

        while ($attempt < $maxRetries) {
            $attempt++;

            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
            ])
                ->timeout($timeout)
                ->post(self::ENDPOINT, [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0,
                    'system' => [
                        [
                            'type' => 'text',
                            'text' => $systemPrompt,
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                    ],
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $inputJson,
                        ],
                    ],
                ]);

            $rawContent = trim($response->json('content.0.text', '{}'));
            $rawContent = $this->stripCodeFences($rawContent);
            $translations = json_decode($rawContent, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($translations)) {
                $translations = $this->flattenArray($translations);
                break;
            }

            if ($attempt < $maxRetries) {
                $error = json_last_error_msg();
                $this->logToConsole("⚠️  Attempt {$attempt}: Failed to decode JSON (error: {$error}), retrying...");
                usleep(10000);
            }
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($translations)) {
            $error = json_last_error_msg();
            $keys = implode(', ', array_keys($texts));
            $this->logToConsole("❌ Failed to decode translation response after {$maxRetries} attempts for keys: {$keys} (JSON error: {$error})");
            $this->logToConsole("⚠️  Skipping this batch and continuing...");
            return [];
        }

        $translatedKeys = implode(', ', array_keys($translations));
        $this->logToConsole("✅ Received translations for: {$translatedKeys}");

        return $translations;
    }

    /**
     * Strip markdown code fences that Claude sometimes wraps JSON output in,
     * even when asked not to.
     */
    protected function stripCodeFences(string $content): string
    {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        return trim($content);
    }

    /**
     * Flatten a nested array structure back into dot notation.
     * Handles cases where the model interprets dots as nested objects.
     */
    protected function flattenArray(array $array, string $prepend = '', bool $isTopLevel = true): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if ($isTopLevel && is_numeric($key) && $prepend === '') {
                continue;
            }

            $newKey = $prepend !== '' ? "{$prepend}.{$key}" : $key;

            if (is_array($value) && !empty($value)) {
                $allKeysAreLangCodes = !empty($value) && count(array_filter(array_keys($value), function ($k) {
                    return is_string($k) && strlen($k) === 2 && ctype_alpha($k);
                })) === count($value);

                if ($allKeysAreLangCodes) {
                    $results[$newKey] = $value;
                } else {
                    $results = array_merge($results, $this->flattenArray($value, $newKey, false));
                }
            } else {
                $results[$newKey] = $value;
            }
        }

        return $results;
    }
}
