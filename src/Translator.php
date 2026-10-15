<?php

namespace EduLazaro\Laratext;

use EduLazaro\Laratext\Contracts\TranslatorInterface;
use Illuminate\Support\Arr;

abstract class Translator implements TranslatorInterface
{
    /**
     * Maximum payload size per request, in characters.
     */
    protected int $maxPayloadChars = 2000;

    /**
     * Maximum texts per request
     */
    protected int $maxBatchItems = 100;

    /**
     * Characters per token assumed when estimating an answer. Low on purpose: English runs
     * near four, but Japanese, Hindi or Thai can take one token for every character or two,
     * and an estimate that is too high only costs one more request.
     */
    protected const CHARS_PER_TOKEN = 2;

    /**
     * How much longer a translation is assumed to be than its source.
     */
    protected const EXPANSION = 1.5;

    /**
     * The share of the output limit a batch is sized to fill, leaving room for the estimate
     * to be wrong.
     */
    protected const OUTPUT_MARGIN = 0.75;

    /**
     * The application context to append to the prompt, empty when none is set.
     *
     * Lets a project tell the translator what the application is about, which
     * register to use, or which terms to leave alone. Configured in
     * `texts.context` and ignored by translators that take no prompt.
     *
     * @return string
     */
    protected function contextInstruction(): string
    {
        $context = trim((string) config('texts.context', ''));

        if ($context === '') {
            return '';
        }

        return " Context about this application, provided by its developers: {$context}";
    }

    /**
     * Outputs a message to the terminal only when running in the console.
     *
     * @param string $message The message to display in the terminal.
     * @return void
     */
    protected function logToConsole(string $message): void
    {
        if (app()->runningInConsole()) {
            echo "\n{$message}\n";
        }
    }

    /**
     * Translate a large set of texts, automatically splitting them into safe batches
     *
     * @param array $texts Array of [key => originalText]
     * @param string $from Source language code
     * @param array $to Target language codes
     * @return array Result as [key => [lang => translatedText]]
     */
    public function batchTranslate(array $texts, string $from, array $to): array
    {
        $limit = $this->outputTokenLimit();

        if ($limit !== null) {
            return $this->batchTranslateWithin($texts, $from, $to, $limit);
        }

        $results = [];
        $batches = $this->splitByPayloadSize($texts);

        // By hand, not array_merge_recursive: a key such as "404" is an integer to PHP, and
        // that function renumbers integer keys, so it was written back as "0".
        foreach ($batches as $batch) {
            foreach ($this->translateMany($batch, $from, $to) as $key => $translations) {
                $results[$key] = ($results[$key] ?? []) + (array) $translations;
            }
        }

        return $results;
    }

    /**
     * The most tokens one answer of this translator may hold, or null when it declares none.
     *
     * A translator that returns a number has its batches sized by what they will produce
     * rather than by what they send: a batch into twenty languages answers twenty times its
     * size. Null keeps the batches cut by `maxPayloadChars` and `maxBatchItems` alone.
     *
     * @return int|null
     */
    protected function outputTokenLimit(): ?int
    {
        return null;
    }

    /**
     * Translates one batch, letting a `ResponseTruncated` through so the batch can be split.
     *
     * The bundled translators override it; by default it is `translateMany()`.
     *
     * @param array<string, string> $texts [key => text]
     * @param string $from
     * @param array<int, string> $to
     * @return array<string, array<string, string>> [key => [lang => translation]]
     *
     * @throws ResponseTruncated
     */
    protected function translateBatch(array $texts, string $from, array $to): array
    {
        return $this->translateMany($texts, $from, $to);
    }

    /**
     * Batches sized to the output limit.
     *
     * The source language is answered with the source text and never sent. The other
     * languages go together unless the longest text into all of them would not fit one
     * answer, in which case they go in groups. A batch whose answer is cut short anyway is
     * split in two, never sent again whole.
     *
     * @param array<string, string> $texts
     * @param string $from
     * @param array<int, string> $to
     * @param int $limit
     * @return array<string, array<string, string>>
     */
    protected function batchTranslateWithin(array $texts, string $from, array $to, int $limit): array
    {
        $results = [];
        $targets = [];

        foreach ($to as $language) {
            if ($this->sameLanguage($language, $from)) {
                foreach ($texts as $key => $text) {
                    $results[$key][$language] = $text;
                }
            } else {
                $targets[] = $language;
            }
        }

        if ($targets === [] || $texts === []) {
            return $results;
        }

        $budget = max(1, (int) floor($limit * static::OUTPUT_MARGIN));

        foreach (array_chunk($targets, $this->languagesPerRequest($texts, count($targets), $budget)) as $group) {
            foreach ($this->splitForOutput($texts, count($group), $budget) as $batch) {
                foreach ($this->translateFitting($batch, $from, $group) as $key => $translations) {
                    foreach ($translations as $language => $translation) {
                        $results[$key][$language] = $translation;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * How many languages one request takes, so that the longest text fits one answer.
     *
     * @param array<string, string> $texts
     * @param int $languages
     * @param int $budget
     * @return int
     */
    protected function languagesPerRequest(array $texts, int $languages, int $budget): int
    {
        $fits = $languages;

        foreach ($texts as $key => $text) {
            while ($fits > 1 && $this->estimatedOutputTokens((string) $key, $text, $fits) > $budget) {
                $fits--;
            }
        }

        return $fits;
    }

    /**
     * Batches whose estimated answer fits the budget, within `maxPayloadChars` and
     * `maxBatchItems` as well, so no batch is ever larger than it was before.
     *
     * @param array<string, string> $texts
     * @param int $languages
     * @param int $budget
     * @return array<int, array<string, string>>
     */
    protected function splitForOutput(array $texts, int $languages, int $budget): array
    {
        $batches = [];
        $current = [];
        $tokens = 0;
        $chars = 0;

        foreach ($texts as $key => $text) {
            $textTokens = $this->estimatedOutputTokens((string) $key, $text, $languages);
            $textChars = mb_strlen($text, 'UTF-8') + mb_strlen((string) $key, 'UTF-8') + 4;

            if ($current !== [] && ($tokens + $textTokens > $budget || $chars + $textChars > $this->maxPayloadChars || count($current) >= $this->maxBatchItems)) {
                $batches[] = $current;
                $current = [];
                $tokens = 0;
                $chars = 0;
            }

            $current[$key] = $text;
            $tokens += $textTokens;
            $chars += $textChars;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * One batch, split in two for as long as its answer comes back cut short.
     *
     * @param array<string, string> $texts
     * @param string $from
     * @param array<int, string> $to
     * @return array<string, array<string, string>>
     */
    protected function translateFitting(array $texts, string $from, array $to): array
    {
        try {
            return $this->translateBatch($texts, $from, $to);
        } catch (ResponseTruncated) {
            if (count($texts) > 1) {
                $half = (int) ceil(count($texts) / 2);

                $this->logToConsole('✂️  The answer did not fit the model\'s output limit; splitting ' . count($texts) . ' texts in two.');

                return $this->translateFitting(array_slice($texts, 0, $half, true), $from, $to)
                    + $this->translateFitting(array_slice($texts, $half, null, true), $from, $to);
            }

            if (count($to) > 1) {
                $half = (int) ceil(count($to) / 2);
                $results = $this->translateFitting($texts, $from, array_slice($to, 0, $half));

                // By hand, not array_merge_recursive: a key such as "404" is an integer to
                // PHP, and that function renumbers integer keys.
                foreach ($this->translateFitting($texts, $from, array_slice($to, $half)) as $key => $translations) {
                    $results[$key] = ($results[$key] ?? []) + $translations;
                }

                return $results;
            }

            $this->logToConsole('❌ [' . array_key_first($texts) . '] does not fit one answer of this model, even alone; skipping it.');

            return [];
        }
    }

    /**
     * The tokens a text's translations take in the answer: its key once, then one
     * translation per language with its code.
     *
     * @param string $key
     * @param string $text
     * @param int $languages
     * @return int
     */
    protected function estimatedOutputTokens(string $key, string $text, int $languages): int
    {
        $keyTokens = (int) ceil(mb_strlen($key, 'UTF-8') / static::CHARS_PER_TOKEN) + 4;
        $translationTokens = (int) ceil(mb_strlen($text, 'UTF-8') * static::EXPANSION / static::CHARS_PER_TOKEN) + 6;

        return $keyTokens + $languages * $translationTokens;
    }

    /**
     * The translations of each key in a decoded answer, filed under the language codes that
     * were asked for.
     *
     * A model may answer `pt-BR` for `pt_BR`, or nest `nav.home` as `{"nav": {"home": ...}}`
     * however firmly it is told not to; both are read back. Anything that is not a string is
     * left out, so the key stays missing and the next scan asks for it again.
     *
     * @param array<string, mixed> $answer
     * @param array<int, string> $keys
     * @param array<int, string> $languages
     * @return array<string, array<string, string>>
     */
    protected function translationsIn(array $answer, array $keys, array $languages): array
    {
        $results = [];

        foreach ($keys as $key) {
            $entry = Arr::get($answer, (string) $key);

            if (! is_array($entry)) {
                continue;
            }

            foreach ($languages as $language) {
                $code = $this->answeredCode($language, $entry);

                if ($code !== null && is_string($entry[$code])) {
                    $results[$key][$language] = $entry[$code];
                }
            }
        }

        return $results;
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
     * Where an answer filed the language asked for: the same code whatever its case and
     * separator, or else its base language (`zh` for `zh_CN`).
     *
     * @param string $asked
     * @param array<string, mixed> $translations
     * @return string|null
     */
    protected function answeredCode(string $asked, array $translations): ?string
    {
        $wanted = $this->canonical($asked);
        $base = explode('-', $wanted)[0];

        foreach (array_keys($translations) as $code) {
            if ($this->canonical((string) $code) === $wanted) {
                return (string) $code;
            }
        }

        foreach (array_keys($translations) as $code) {
            if ($this->canonical((string) $code) === $base) {
                return (string) $code;
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

    /**
     * Split texts into multiple batches based on estimated total character length
     *
     * @param array $texts
     * @return array[] Array of batches: each is [key => text]
     */
    protected function splitByPayloadSize(array $texts): array
    {
        $batches = [];
        $currentBatch = [];
        $currentLength = 0;

        foreach ($texts as $key => $text) {

            $textLength = mb_strlen($text, 'UTF-8') + mb_strlen($key, 'UTF-8') + 4;

            if (
                (!empty($currentBatch) && ($currentLength + $textLength > $this->maxPayloadChars))
                || (count($currentBatch) >= $this->maxBatchItems)
            ) {
                $batches[] = $currentBatch;
                $currentBatch = [];
                $currentLength = 0;
            }

            $currentBatch[$key] = $text;
            $currentLength += $textLength;
        }

        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }

        return $batches;
    }

    /**
     * Must be implemented by concrete translators
     * Translates a single string into multiple languages
     *
     * @param string $text
     * @param string $from
     * @param array $to
     * @return array [lang => translatedText]
     */
    abstract public function translate(string $text, string $from, array $to): array;

    /**
     * Translate a batch of texts. You can override this for optimized batch calls
     * Default implementation calls `translate()` for each text
     *
     * @param array $texts [key => text]
     * @param string $from
     * @param array $to
     * @return array [key => [lang => translation]]
     */
    public function translateMany(array $texts, string $from, array $to): array
    {
        $results = [];

        foreach ($texts as $key => $text) {
            $results[$key] = $this->translate($text, $from, $to);
        }

        return $results;
    }
}
