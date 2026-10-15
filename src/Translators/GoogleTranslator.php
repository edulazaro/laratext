<?php

namespace EduLazaro\Laratext\Translators;

use EduLazaro\Laratext\Contracts\TranslatorInterface;
use EduLazaro\Laratext\Translator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleTranslator extends Translator implements TranslatorInterface
{
    protected const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

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
        return $this->translateMany(['text' => $text], $from, $to)['text'] ?? [];
    }

    /**
     * Translates multiple strings into multiple target languages.
     *
     * One request per language, as the API takes one target at a time. The source language
     * is answered with the source text and never sent, and a language the API refuses is
     * reported and left out, so its keys stay missing and the next scan asks again.
     *
     * @param array<string, string> $texts Array of texts with their corresponding keys.
     * @param string $from The source language code (e.g., 'en').
     * @param array $to An array of target language codes (e.g., ['es', 'fr']).
     * @return array<string, array<string, string>> Translations indexed by original key and language code.
     */
    public function translateMany(array $texts, string $from, array $to): array
    {
        $keys = array_keys($texts);
        $values = array_map('strval', array_values($texts));
        $results = [];

        foreach ($to as $language) {
            if ($this->sameLanguage($language, $from)) {
                foreach ($texts as $key => $text) {
                    $results[$key][$language] = $text;
                }

                continue;
            }

            foreach ((array) $this->request($values, $from, $language) as $index => $item) {
                $key = $keys[$index] ?? null;

                if ($key !== null && is_string($item['translatedText'] ?? null)) {
                    $results[$key][$language] = $item['translatedText'];
                }
            }
        }

        return $results;
    }

    /**
     * One language, asked again on a rate limit or a server error.
     *
     * Laravel writes `pt_BR` where Google reads `pt-BR`. A regional code Google does not
     * know is asked again as its base language (`en-GB` as `en`), and the source is always
     * sent as its base language, which is all Google needs to read it.
     *
     * @param array<int, string> $values
     * @param string $from
     * @param string $language
     * @return array<int, array<string, mixed>>|null The translations in order, or null.
     */
    protected function request(array $values, string $from, string $language): ?array
    {
        $attempts = max(1, (int) config('texts.google.retries', 3));
        $target = str_replace('_', '-', trim($language));
        $base = explode('-', $this->canonical($language))[0];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = Http::timeout((int) config('texts.google.timeout', 20))
                ->post(static::ENDPOINT, [
                    'q' => $values,
                    'source' => explode('-', $this->canonical($from))[0],
                    'target' => $target,
                    'format' => 'text',
                    'key' => config('texts.google.api_key'),
                ]);

            if ($response->successful()) {
                return (array) $response->json('data.translations', []);
            }

            if ($response->status() === 400 && $target !== $base) {
                $this->logToConsole("⚠️  Google does not take [{$target}]; asking for [{$base}] instead.");
                $target = $base;
                $attempt--;

                continue;
            }

            $this->logToConsole("⚠️  Google answered {$response->status()} for [{$language}]: " . ($response->json('error.message') ?? 'no message'));

            if (! $this->retryable($response) || $attempt === $attempts) {
                return null;
            }

            usleep(min(8, 2 ** $attempt) * 250_000);
        }

        return null;
    }

    /**
     * Perform the translation request for a single target language.
     *
     * Kept for translators that extend this one.
     *
     * @param string $text
     * @param string $from
     * @param string $to
     * @return string
     */
    protected function translateTo(string $text, string $from, string $to): string
    {
        return trim($this->translate($text, $from, [$to])[$to] ?? '');
    }

    /**
     * @param Response $response
     * @return bool
     */
    protected function retryable(Response $response): bool
    {
        return $response->status() === 429 || $response->status() >= 500;
    }
}
