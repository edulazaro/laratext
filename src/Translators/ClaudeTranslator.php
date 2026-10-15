<?php

namespace EduLazaro\Laratext\Translators;

use EduLazaro\Laratext\Contracts\TranslatorInterface;
use EduLazaro\Laratext\ModelLimits;
use EduLazaro\Laratext\ResponseTruncated;
use EduLazaro\Laratext\Translator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeTranslator extends Translator implements TranslatorInterface
{
    protected const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    protected const API_VERSION = '2023-06-01';

    /**
     * The longest answer asked of the model by default, in tokens; `texts.claude.max_tokens`
     * changes it, and the model's own limit caps it.
     */
    protected const DEFAULT_MAX_TOKENS = 4096;

    /**
     * Tokens left for a model that thinks by default, on top of the answer: thinking counts
     * against `max_tokens`. Well under the 32,000 past which Anthropic sends thinking to
     * batch processing.
     */
    protected const THINKING_RESERVE = 16000;

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
        $languagesList = implode(', ', $to);

        $answer = $this->ask(
            "You are a helpful assistant that translates from {$from} into multiple languages: {$languagesList}. Reply ONLY with a valid JSON object (no markdown, no code blocks, no explanations), where each property is the language code and the value is the translated text. Preserve placeholders like :name, :count, or any text wrapped in colons (:) exactly as they are. IMPORTANT: Do NOT create nested objects. Return a flat JSON object." . $this->contextInstruction(),
            $text,
        );

        if ($answer === null) {
            throw new RuntimeException('Failed to decode translation response.');
        }

        return $this->translationsIn(['text' => $answer], ['text'], $to)['text'] ?? [];
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
        try {
            return $this->translateBatch($texts, $from, $to);
        } catch (ResponseTruncated) {
            $this->logToConsole('❌ The answer did not fit the model\'s output limit. Skipping this batch and continuing...');

            return [];
        }
    }

    /**
     * @param array<string, string> $texts
     * @param string $from
     * @param array<int, string> $to
     * @return array<string, array<string, string>>
     *
     * @throws ResponseTruncated
     */
    protected function translateBatch(array $texts, string $from, array $to): array
    {
        $languagesList = implode(', ', $to);
        $keys = array_map('strval', array_keys($texts));

        $this->logToConsole('➡️ Sending ' . count($texts) . " texts to Claude for translation into [{$languagesList}] using model [{$this->model()}]...");

        $answer = $this->ask(
            "You are a helpful assistant that translates JSON key-value pairs from {$from} into multiple languages: {$languagesList}. Reply ONLY with a valid JSON object (no markdown, no code blocks, no explanations) where each key from the input maps to an object of translations per language. Preserve any placeholder like :name, :count, or any text wrapped in colons (:). CRITICAL: Keep ALL keys EXACTLY as they appear in the input, including dots and numbers (e.g., 'properties.parking_type', 'items.0.name'). Do NOT interpret dots as nested objects. Do NOT create any nested structure. Return keys as-is. You MAY use the keys as context to improve the translation, since they indicate where the text is used (for example nav., errors. or buttons.)." . $this->contextInstruction(),
            json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        if ($answer === null) {
            $this->logToConsole('❌ No usable answer for keys: ' . implode(', ', $keys) . '. Skipping this batch and continuing...');

            return [];
        }

        $translations = $this->translationsIn($answer, $keys, $to);

        $this->logToConsole('✅ Received translations for: ' . implode(', ', array_keys($translations)));

        return $translations;
    }

    /**
     * Sizes the batches: the answer asked for, capped by the model's own limit.
     *
     * @return int
     */
    protected function outputTokenLimit(): int
    {
        return $this->maxTokens();
    }

    /**
     * One request with the parameters the model accepts, asked again when the JSON does not
     * decode or the API is busy, never when the answer was cut short.
     *
     * @param string $system
     * @param string $user
     * @return array<string, mixed>|null The decoded answer, or null when none came.
     *
     * @throws ResponseTruncated
     */
    protected function ask(string $system, string $user): ?array
    {
        $attempts = max(1, (int) config('texts.claude.retries', 3));

        $body = [
            'model' => $this->model(),
            'max_tokens' => min($this->limits()->maxOutputTokens, $this->maxTokens() + ($this->limits()->reasoning ? static::THINKING_RESERVE : 0)),
            'system' => [
                [
                    'type' => 'text',
                    'text' => $system,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if ($this->limits()->temperature) {
            $body['temperature'] = 0;
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('texts.claude.api_key'),
                'anthropic-version' => self::API_VERSION,
            ])
                ->timeout((int) config('texts.claude.timeout', 60))
                ->post(self::ENDPOINT, $body);

            if (! $response->successful()) {
                $this->logToConsole('⚠️  Claude answered ' . $response->status() . ': ' . ($response->json('error.message') ?? 'no message'));

                if (! $this->retryable($response) || $attempt === $attempts) {
                    return null;
                }

                usleep(min(8, 2 ** $attempt) * 250_000);

                continue;
            }

            if ($response->json('stop_reason') === 'max_tokens') {
                throw new ResponseTruncated('Claude stopped at the output limit.');
            }

            // A model that thinks first answers a `thinking` block before the text one.
            $text = collect((array) $response->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $answer = json_decode($this->stripCodeFences(trim((string) $text)), true);

            if (is_array($answer)) {
                return $answer;
            }

            if ($attempt < $attempts) {
                $this->logToConsole("⚠️  Attempt {$attempt}: Failed to decode JSON (error: " . json_last_error_msg() . '), retrying...');
            }
        }

        return null;
    }

    /**
     * A rate limit, an overload (529) or a server error is worth asking again.
     *
     * @param Response $response
     * @return bool
     */
    protected function retryable(Response $response): bool
    {
        return $response->status() === 429 || $response->status() >= 500;
    }

    /**
     * @return int
     */
    protected function maxTokens(): int
    {
        return min(max(256, (int) config('texts.claude.max_tokens', static::DEFAULT_MAX_TOKENS)), $this->limits()->maxOutputTokens);
    }

    /**
     * @return string
     */
    protected function model(): string
    {
        return (string) config('texts.claude.model', 'claude-haiku-4-5');
    }

    /**
     * @return ModelLimits
     */
    protected function limits(): ModelLimits
    {
        return ModelLimits::for('claude', $this->model());
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
     *
     * Kept for translators that extend this one; answers are now read by `translationsIn()`.
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
