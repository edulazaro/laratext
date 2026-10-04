<?php

namespace EduLazaro\Laratext\Translators;

use EduLazaro\Laratext\Contracts\TranslatorInterface;
use EduLazaro\Laratext\Translator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Translates through the Translatorio API (translatorio.com).
 *
 * Translatorio protects placeholders such as `:name`, `{count}` and HTML tags itself and
 * reports whether every one came back, so nothing here has to ask a model nicely to keep
 * them. One request carries a whole batch into every language.
 */
class TranslatorioTranslator extends Translator implements TranslatorInterface
{
    /**
     * Translatorio takes up to 100 texts and 50,000 characters a call; a batch stays well
     * under both so a long catalogue never reaches either limit.
     */
    protected int $maxPayloadChars = 20000;

    protected int $maxBatchItems = 100;

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
        $results = $this->request([$text], $from, $to);

        return $results[0]['translations'] ?? [];
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
        if ($texts === []) {
            return [];
        }

        $keys = array_keys($texts);
        $count = count($texts);
        $this->logToConsole("➡️ Sending {$count} texts to Translatorio for translation into [" . implode(', ', $to) . ']...');

        $results = $this->request(array_values($texts), $from, $to);
        $translations = [];

        foreach ($results as $result) {
            $key = $keys[$result['index'] ?? -1] ?? null;

            if ($key === null) {
                continue;
            }

            if (($result['intact'] ?? true) === false) {
                $this->logToConsole("⚠️  [{$key}] a placeholder did not survive the translation; check it by hand.");
            }

            $translations[$key] = $result['translations'] ?? [];
        }

        $this->logToConsole('✅ Received translations for: ' . implode(', ', array_keys($translations)));

        return $translations;
    }

    /**
     * One call to /v1/translate, retried on 429 and 5xx with the same Idempotency-Key.
     *
     * A 402 is never retried: it means the account is out of credits, and retrying it only
     * delays the message saying so.
     *
     * @param array<int, string> $texts
     * @param string $from
     * @param array<int, string> $to
     * @return array<int, array{index: int, translations: array<string, string>, intact?: bool}>
     */
    protected function request(array $texts, string $from, array $to): array
    {
        $apiKey = config('texts.translatorio.api_key');

        if (! filled($apiKey)) {
            throw new RuntimeException('Set TRANSLATORIO_API_KEY (texts.translatorio.api_key) to translate with Translatorio.');
        }

        $body = array_filter([
            'texts' => array_values($texts),
            'source' => $from,
            'targets' => array_values($to),
            'format' => config('texts.translatorio.format', 'text'),
            'formality' => config('texts.translatorio.formality'),
            'glossary' => config('texts.translatorio.glossary'),
            'context' => trim((string) config('texts.context', '')) ?: null,
        ], fn ($value) => $value !== null && $value !== '');

        $retries = max(0, (int) config('texts.translatorio.retries', 3));
        $idempotencyKey = (string) Str::uuid();
        $response = null;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            if ($attempt > 0) {
                usleep(min(8, 2 ** ($attempt - 1)) * 500_000);
            }

            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->timeout((int) config('texts.translatorio.timeout', 60))
                    ->withoutRedirecting()
                    ->post(rtrim((string) config('texts.translatorio.url', 'https://translatorio.com'), '/') . '/api/v1/translate', $body);
            } catch (ConnectionException $e) {
                $response = null;

                continue;
            }

            if ($response->status() !== 429 && $response->status() < 500) {
                break;
            }
        }

        if (! $response instanceof Response) {
            throw new RuntimeException('Translatorio could not be reached.');
        }

        // Only a 2xx JSON answer is a translation: a redirect or a proxy's HTML page must
        // never be read as one.
        if (! $response->successful() || ! is_array($response->json('results'))) {
            $message = $response->json('error.message') ?? "HTTP {$response->status()}";

            throw new RuntimeException("Translatorio refused the batch: {$message}");
        }

        return $response->json('results');
    }
}
