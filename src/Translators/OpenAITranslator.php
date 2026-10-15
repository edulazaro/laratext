<?php

namespace EduLazaro\Laratext\Translators;

use EduLazaro\Laratext\Contracts\TranslatorInterface;
use EduLazaro\Laratext\ModelLimits;
use EduLazaro\Laratext\ResponseTruncated;
use EduLazaro\Laratext\Translator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAITranslator extends Translator implements TranslatorInterface
{
    protected const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * The longest answer asked of the model by default, in tokens: what a request returns
     * well inside the default timeout. `texts.openai.max_output_tokens` changes it, and the
     * model's own limit caps it.
     */
    protected const DEFAULT_OUTPUT_TOKENS = 4096;

    /**
     * Tokens left for a reasoning model to think in, on top of the answer: OpenAI advises
     * reserving 25,000 for reasoning and output at first.
     */
    protected const REASONING_RESERVE = 25000;

    /**
     * Structured Outputs takes up to 5,000 properties and 120,000 characters of names in a
     * schema; a larger one is asked for as plain JSON instead.
     */
    protected const MAX_SCHEMA_PROPERTIES = 5000;

    protected const MAX_SCHEMA_NAME_CHARS = 120000;

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
        $languagesList = implode(', ', $to);

        $answer = $this->ask(
            "You are a helpful assistant that translates from {$from} into multiple languages: {$languagesList}. Reply ONLY with a valid JSON object (no markdown, no code blocks, no explanations), where each property is the language code and the value is the translated text. Preserve placeholders like :name, :count, or any text wrapped in colons (:) exactly as they are. IMPORTANT: Do NOT create nested objects. Return a flat JSON object." . $this->contextInstruction(),
            $text,
            $this->schema(null, $to),
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

        $this->logToConsole('➡️ Sending ' . count($texts) . " texts to OpenAI for translation into [{$languagesList}] using model [{$this->model()}]...");

        $answer = $this->ask(
            "You are a helpful assistant that translates JSON key-value pairs from {$from} into multiple languages: {$languagesList}. Reply ONLY with a valid JSON object (no markdown, no code blocks, no explanations) where each key from the input maps to an object of translations per language. Preserve any placeholder like :name, :count, or any text wrapped in colons (:). CRITICAL: Keep ALL keys EXACTLY as they appear in the input, including dots and numbers (e.g., 'properties.parking_type', 'items.0.name'). Do NOT interpret dots as nested objects. Do NOT create any nested structure. Return keys as-is. You MAY use the keys as context to improve the translation, since they indicate where the text is used (for example nav., errors. or buttons.)." . $this->contextInstruction(),
            json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            $this->schema($keys, $to),
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
        return min($this->requestedOutputTokens(), $this->limits()->maxOutputTokens);
    }

    /**
     * The `max_completion_tokens` sent: the answer, plus room to think for a reasoning
     * model, whose reasoning tokens count against the same allowance.
     *
     * @return int
     */
    protected function completionTokens(): int
    {
        $limits = $this->limits();

        return min($limits->maxOutputTokens, $this->outputTokenLimit() + ($limits->reasoning ? static::REASONING_RESERVE : 0));
    }

    /**
     * One request with the parameters the model accepts, asked again when the JSON does not
     * decode or the API is busy, never when the answer was cut short.
     *
     * @param string $system
     * @param string $user
     * @param array<string, mixed> $schema
     * @return array<string, mixed>|null The decoded answer, or null when none came.
     *
     * @throws ResponseTruncated
     */
    protected function ask(string $system, string $user, array $schema): ?array
    {
        $limits = $this->limits();
        $attempts = max(1, (int) config('texts.openai.retries', 3));

        $body = [
            'model' => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_completion_tokens' => $this->completionTokens(),
        ];

        if ($limits->temperature) {
            $body['temperature'] = 0;
        }

        // A published config holds `reasoning_effort => null`, which config() returns as it is.
        if ($limits->reasoningEffort !== null) {
            $body['reasoning_effort'] = (string) (config('texts.openai.reasoning_effort') ?: $limits->reasoningEffort);
        }

        if ($limits->json === ModelLimits::JSON_SCHEMA && $this->schemaFits($schema)) {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'translations', 'strict' => true, 'schema' => $schema]];
        } elseif ($limits->json !== ModelLimits::JSON_NONE) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = Http::withToken((string) config('texts.openai.api_key'))
                ->timeout((int) config('texts.openai.timeout', 60))
                ->post(static::ENDPOINT, $body);

            if (! $response->successful()) {
                $this->logToConsole('⚠️  OpenAI answered ' . $response->status() . ': ' . ($response->json('error.message') ?? 'no message'));

                if (! $this->retryable($response) || $attempt === $attempts) {
                    return null;
                }

                usleep(min(8, 2 ** $attempt) * 250_000);

                continue;
            }

            if ($response->json('choices.0.finish_reason') === 'length') {
                throw new ResponseTruncated('OpenAI stopped at the output limit.');
            }

            $answer = json_decode(trim((string) $response->json('choices.0.message.content', '')), true);

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
     * The Structured Outputs schema of the answer: every key, every language, nothing else.
     * With no keys, the flat `{language: translation}` of a single text.
     *
     * @param array<int, string>|null $keys
     * @param array<int, string> $languages
     * @return array<string, mixed>
     */
    protected function schema(?array $keys, array $languages): array
    {
        $perLanguage = [
            'type' => 'object',
            'properties' => array_fill_keys($languages, ['type' => 'string']),
            'required' => array_values($languages),
            'additionalProperties' => false,
        ];

        if ($keys === null) {
            return $perLanguage;
        }

        return [
            'type' => 'object',
            'properties' => array_fill_keys($keys, $perLanguage),
            'required' => $keys,
            'additionalProperties' => false,
        ];
    }

    /**
     * Whether a schema is within Structured Outputs' limits on properties and name length.
     *
     * @param array<string, mixed> $schema
     * @return bool
     */
    protected function schemaFits(array $schema): bool
    {
        $properties = 0;
        $chars = 0;
        $pending = [$schema];

        while ($pending !== []) {
            $node = array_pop($pending);

            foreach ((array) ($node['properties'] ?? []) as $name => $child) {
                $properties++;
                $chars += strlen((string) $name);
                $pending[] = $child;
            }
        }

        return $properties <= static::MAX_SCHEMA_PROPERTIES && $chars <= static::MAX_SCHEMA_NAME_CHARS;
    }

    /**
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
    protected function requestedOutputTokens(): int
    {
        return max(256, (int) config('texts.openai.max_output_tokens', static::DEFAULT_OUTPUT_TOKENS));
    }

    /**
     * @return string
     */
    protected function model(): string
    {
        return (string) config('texts.openai.model', 'gpt-5.4-nano');
    }

    /**
     * @return ModelLimits
     */
    protected function limits(): ModelLimits
    {
        return ModelLimits::for('openai', $this->model());
    }

    /**
     * Flatten a nested array structure back into dot notation.
     * This handles cases where OpenAI incorrectly interprets dots as nested objects.
     *
     * Kept for translators that extend this one; answers are now read by `translationsIn()`.
     *
     * @param array $array The array to flatten
     * @param string $prepend The prefix for keys (used in recursion)
     * @param bool $isTopLevel Whether this is the top level (to skip pure numeric keys)
     * @return array Flattened array with dot notation keys
     */
    protected function flattenArray(array $array, string $prepend = '', bool $isTopLevel = true): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            // Skip ONLY pure numeric keys at the top level (these are invalid translation keys like "0": "text")
            // But preserve numeric keys in paths like "items.0.description"
            if ($isTopLevel && is_numeric($key) && $prepend === '') {
                continue;
            }

            // Build the new key with dot notation
            $newKey = $prepend !== '' ? "{$prepend}.{$key}" : $key;

            if (is_array($value) && !empty($value)) {
                // Check if this array contains language codes (es, en, zh, etc.)
                // If so, this is a translation object and we should NOT flatten it
                $allKeysAreLangCodes = !empty($value) && count(array_filter(array_keys($value), function($k) {
                    return is_string($k) && strlen($k) === 2 && ctype_alpha($k);
                })) === count($value);

                if ($allKeysAreLangCodes) {
                    // This is a translation object like {"es": "Garaje", "zh": "车库"}
                    // Don't flatten it, keep it as is
                    $results[$newKey] = $value;
                } else {
                    // This is a nested structure that needs flattening
                    $results = array_merge($results, $this->flattenArray($value, $newKey, false));
                }
            } else {
                // Leaf value - add it
                $results[$newKey] = $value;
            }
        }

        return $results;
    }
}
