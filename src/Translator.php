<?php

namespace EduLazaro\Laratext;

use EduLazaro\Laratext\Contracts\TranslatorInterface;

abstract class Translator implements TranslatorInterface
{
    /**
     * Maximum payload size per request, in characters.
     */
    protected int $maxPayloadChars = 10000;

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
        $results = [];
        $batches = $this->splitByPayloadSize($texts);

        foreach ($batches as $batch) {
            $translated = $this->translateMany($batch, $from, $to);
            $results = array_merge_recursive($results, $translated);
        }

        return $results;
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
            $textLength = mb_strlen($text, 'UTF-8');

            if ($currentLength + $textLength > $this->maxPayloadChars && !empty($currentBatch)) {
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
