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
 * them. One request carries a batch into up to twenty languages.
 */
class TranslatorioTranslator extends Translator implements TranslatorInterface
{
    /**
     * Translatorio takes up to 100 texts and 50,000 characters a call; a batch stays well
     * under both so a long catalogue never reaches either limit.
     */
    protected int $maxPayloadChars = 20000;

    protected int $maxBatchItems = 100;

    /** The most target languages one call takes. More are sent in several calls. */
    protected const MAX_TARGETS = 20;

    /** The longest `context` one call takes, in characters. */
    protected const MAX_CONTEXT = 30000;

    /** The longest wait honoured from a `Retry-After`, in seconds. */
    protected const MAX_WAIT = 30;

    /**
     * Whether a batch came back translated in this run. Until one has, an error has cost
     * nothing and stops the scan; after, it would throw away what was already paid for.
     */
    protected bool $translatedAny = false;

    /** Set once the account runs out of credits: the batches left are not sent. */
    protected bool $outOfCredits = false;

    /** Whether the warning about a long context was already printed. */
    protected bool $contextWarned = false;

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
        return $this->translateBatch(['text' => $text], $from, $to)['text'] ?? [];
    }

    /**
     * Translates multiple strings into multiple target languages.
     *
     * A batch that fails once others have succeeded is left out and reported, as the
     * other translators do: the scan writes what came back, and the keys left out are
     * still missing, so the next scan sends them again.
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

        if ($this->outOfCredits) {
            $this->logToConsole('⏭️  Skipping ' . count($texts) . ' texts: the Translatorio account is out of credits.');

            return [];
        }

        $this->logToConsole('➡️ Sending ' . count($texts) . ' texts to Translatorio for translation into [' . implode(', ', $to) . ']...');

        try {
            $translations = $this->translateBatch($texts, $from, $to);
        } catch (RuntimeException $e) {
            if (! $this->translatedAny) {
                throw $e;
            }

            if ($e->getCode() === 402) {
                $this->outOfCredits = true;
                $this->logToConsole("❌ {$e->getMessage()} The keys translated so far are kept; run the scan again once there are credits to finish the rest.");
            } else {
                $this->logToConsole("❌ {$e->getMessage()} Skipping these " . count($texts) . ' keys; the next scan sends them again.');
            }

            return [];
        }

        $this->translatedAny = true;
        $this->logToConsole('✅ Received translations for: ' . implode(', ', array_keys($translations)));

        return $translations;
    }

    /**
     * One batch into every language: the source language is answered with the text itself,
     * the rest go in calls of at most twenty languages, plain texts and texts with HTML
     * apart, and each answer is filed under the language code it was asked for.
     *
     * @param array<string, string> $texts
     * @param string $from
     * @param array<int, string> $to
     * @return array<string, array<string, string>>
     *
     * @throws RuntimeException
     */
    protected function translateBatch(array $texts, string $from, array $to): array
    {
        $translations = [];
        $targets = [];

        foreach ($to as $language) {
            if ($this->sameLanguage($language, $from)) {
                foreach ($texts as $key => $text) {
                    $translations[$key][$language] = $text;
                }
            } else {
                $targets[] = $language;
            }
        }

        foreach ($this->byFormat($texts) as $format => $group) {
            $keys = array_keys($group);

            foreach (array_chunk($targets, self::MAX_TARGETS) as $chunk) {
                foreach ($this->request(array_values($group), $from, $chunk, $format) as $result) {
                    $key = $keys[$result['index'] ?? -1] ?? null;

                    if ($key === null) {
                        continue;
                    }

                    if (($result['intact'] ?? true) === false) {
                        $this->logToConsole("⚠️  [{$key}] a placeholder did not survive the translation; check it by hand.");
                    }

                    foreach ($chunk as $language) {
                        $answered = $this->answeredCode($language, (array) ($result['translations'] ?? []));

                        if ($answered !== null) {
                            $translations[$key][$language] = $result['translations'][$answered];
                        }
                    }
                }
            }
        }

        return $translations;
    }

    /**
     * The texts grouped by the format they are sent as.
     *
     * With `format` set to `auto`, a text with HTML tags goes as `html`, so its tags are
     * protected too, and the rest as `text`. Any other value is used for every text.
     *
     * @param array<string, string> $texts
     * @return array<string, array<string, string>>
     */
    protected function byFormat(array $texts): array
    {
        $format = (string) config('texts.translatorio.format', 'auto');

        if ($format !== 'auto') {
            return [$format => $texts];
        }

        $groups = [];

        foreach ($texts as $key => $text) {
            $groups[preg_match('/<\/?[a-zA-Z][^>]*>/', $text) ? 'html' : 'text'][$key] = $text;
        }

        return $groups;
    }

    /**
     * One call to /v1/translate, retried with the same Idempotency-Key on a connection
     * failure, a 429, a 5xx and the 409 that says the same call is still running.
     *
     * A 402 is never retried: it means the account is out of credits, and retrying it only
     * delays the message saying so.
     *
     * @param array<int, string> $texts
     * @param string $from
     * @param array<int, string> $to
     * @param string $format
     * @return array<int, array{index: int, translations: array<string, string>, intact?: bool}>
     *
     * @throws RuntimeException With the HTTP status as its code.
     */
    protected function request(array $texts, string $from, array $to, string $format): array
    {
        $apiKey = config('texts.translatorio.api_key');

        if (! filled($apiKey)) {
            throw new RuntimeException('Set TRANSLATORIO_API_KEY (texts.translatorio.api_key) to translate with Translatorio.');
        }

        $body = array_filter([
            'texts' => array_values($texts),
            'source' => $from,
            'targets' => array_values($to),
            'format' => $format,
            'formality' => config('texts.translatorio.formality'),
            'glossary' => config('texts.translatorio.glossary'),
            'effort' => config('texts.translatorio.effort'),
            'context' => $this->context(),
        ], fn ($value) => $value !== null && $value !== '');

        $retries = max(0, (int) config('texts.translatorio.retries', 3));
        $idempotencyKey = (string) Str::uuid();
        $response = null;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->timeout((int) config('texts.translatorio.timeout', 180))
                    ->withoutRedirecting()
                    ->post(rtrim((string) config('texts.translatorio.url', 'https://translatorio.com'), '/') . '/api/v1/translate', $body);
            } catch (ConnectionException $e) {
                $response = null;

                if ($attempt < $retries) {
                    $this->pause($this->backoff($attempt));
                }

                continue;
            }

            if (! $this->retryable($response)) {
                break;
            }

            if ($attempt < $retries) {
                $this->pause($this->waitFor($response, $attempt));
            }
        }

        if (! $response instanceof Response) {
            throw new RuntimeException('Translatorio could not be reached.');
        }

        // Only a 2xx JSON answer is a translation: a redirect or a proxy's HTML page must
        // never be read as one.
        if (! $response->successful() || ! is_array($response->json('results'))) {
            $message = $response->json('error.message') ?? "HTTP {$response->status()}";

            throw new RuntimeException("Translatorio refused the batch: {$message}", $response->status());
        }

        return $response->json('results');
    }

    /**
     * The application context, cut to what one call takes, with a warning the first time.
     *
     * @return string|null
     */
    protected function context(): ?string
    {
        $context = trim((string) config('texts.context', ''));

        if (mb_strlen($context) > self::MAX_CONTEXT) {
            if (! $this->contextWarned) {
                $this->contextWarned = true;
                $this->logToConsole('⚠️  texts.context has ' . mb_strlen($context) . ' characters; Translatorio reads the first ' . number_format(self::MAX_CONTEXT) . '.');
            }

            $context = mb_substr($context, 0, self::MAX_CONTEXT);
        }

        return $context === '' ? null : $context;
    }

    /**
     * Whether an answer is worth asking again for: a rate limit, a server error, or the
     * same call still running after a timeout cut the first attempt short.
     *
     * @param Response $response
     * @return bool
     */
    protected function retryable(Response $response): bool
    {
        return $response->status() === 429
            || $response->status() >= 500
            || ($response->status() === 409 && $response->json('error.code') === 'idempotency_in_flight');
    }

    /**
     * How long the API asked to wait, in its header or its body, or else the backoff.
     *
     * @param Response $response
     * @param int $attempt
     * @return float Seconds.
     */
    protected function waitFor(Response $response, int $attempt): float
    {
        $asked = $response->header('Retry-After') ?: $response->json('error.retry_after');

        return is_numeric($asked) ? min(self::MAX_WAIT, max(0, (float) $asked)) : $this->backoff($attempt);
    }

    /**
     * @param int $attempt
     * @return float Seconds: 0.5, 1, 2, then 4.
     */
    protected function backoff(int $attempt): float
    {
        return min(8, 2 ** $attempt) * 0.5;
    }

    /**
     * @param float $seconds
     * @return void
     */
    protected function pause(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * Whether two codes name the same language: `pt_BR` and `pt-BR` do, `en` and `en_GB`
     * do not.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    protected function sameLanguage(string $a, string $b): bool
    {
        return $this->canonical($a) === $this->canonical($b);
    }

    /**
     * Where the answer filed the language asked for. Translatorio answers in its own codes,
     * `pt-BR` for `pt_BR` and `zh` for `zh_CN`, the way it reads them: the same code
     * whatever the case and separator, or else its base language.
     *
     * @param string $asked
     * @param array<string, string> $translations
     * @return string|null
     */
    protected function answeredCode(string $asked, array $translations): ?string
    {
        $wanted = $this->canonical($asked);
        $base = explode('-', $wanted)[0];

        foreach (array_keys($translations) as $code) {
            if ($this->canonical($code) === $wanted) {
                return $code;
            }
        }

        foreach (array_keys($translations) as $code) {
            if ($this->canonical($code) === $base) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param string $code
     * @return string
     */
    protected function canonical(string $code): string
    {
        return strtolower(str_replace('_', '-', trim($code)));
    }
}
