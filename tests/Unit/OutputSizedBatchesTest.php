<?php

use EduLazaro\Laratext\ResponseTruncated;
use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Translator;

/**
 * Batches sized by what they produce: a translator that declares an output limit has its
 * source language answered locally, its languages grouped when one text into all of them
 * would not fit, and a cut-short answer split instead of sent again.
 */
class OutputSizedBatchesTest extends TestCase
{
    /**
     * A translator that records every request and answers "[lang] text", or throws
     * ResponseTruncated for any request with more estimated tokens than `$truncateAbove`.
     */
    private function translator(?int $limit, ?int $truncateAbove = null): Translator
    {
        return new class($limit, $truncateAbove) extends Translator {
            /** @var array<int, array{keys: array<int, string>, languages: array<int, string>}> */
            public array $requests = [];

            public function __construct(private ?int $limit, private ?int $truncateAbove)
            {
            }

            public function translate(string $text, string $from, array $to): array
            {
                return [];
            }

            public function translateMany(array $texts, string $from, array $to): array
            {
                $this->requests[] = ['keys' => array_map('strval', array_keys($texts)), 'languages' => $to];

                $results = [];

                foreach ($texts as $key => $text) {
                    foreach ($to as $language) {
                        $results[$key][$language] = "[{$language}] {$text}";
                    }
                }

                return $results;
            }

            protected function translateBatch(array $texts, string $from, array $to): array
            {
                if ($this->truncateAbove !== null) {
                    $tokens = 0;

                    foreach ($texts as $key => $text) {
                        $tokens += $this->estimatedOutputTokens((string) $key, $text, count($to));
                    }

                    if ($tokens > $this->truncateAbove) {
                        $this->requests[] = ['keys' => array_map('strval', array_keys($texts)), 'languages' => $to, 'truncated' => true];

                        throw new ResponseTruncated('cut');
                    }
                }

                return $this->translateMany($texts, $from, $to);
            }

            protected function outputTokenLimit(): ?int
            {
                return $this->limit;
            }

            public function estimate(string $key, string $text, int $languages): int
            {
                return $this->estimatedOutputTokens($key, $text, $languages);
            }
        };
    }

    public function test_without_a_limit_batches_are_cut_as_they_always_were()
    {
        $translator = $this->translator(null);

        $result = $translator->batchTranslate(['a' => 'One', 'b' => 'Two'], 'en', ['en', 'es']);

        $this->assertCount(1, $translator->requests);
        $this->assertSame(['en', 'es'], $translator->requests[0]['languages'], 'the source language is still sent, as before');
        $this->assertSame('[es] One', $result['a']['es']);
    }

    public function test_the_source_language_is_answered_with_the_source_and_never_sent()
    {
        $translator = $this->translator(4096);

        $result = $translator->batchTranslate(['a' => 'One'], 'en', ['en', 'es', 'pt_BR']);

        $this->assertSame(['es', 'pt_BR'], $translator->requests[0]['languages']);
        $this->assertSame('One', $result['a']['en']);
        $this->assertSame('[pt_BR] One', $result['a']['pt_BR']);
    }

    public function test_nothing_is_sent_when_the_only_target_is_the_source()
    {
        $translator = $this->translator(4096);

        $this->assertSame(['a' => ['en' => 'One']], $translator->batchTranslate(['a' => 'One'], 'en', ['en']));
        $this->assertSame([], $translator->requests);
    }

    public function test_batches_follow_the_answer_they_produce_not_the_text_they_send()
    {
        $texts = [];

        for ($i = 0; $i < 20; $i++) {
            $texts["key{$i}"] = str_repeat('word ', 20);
        }

        $languages = ['es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'sv', 'da', 'fi', 'cs', 'ro'];
        $translator = $this->translator(2000);

        $result = $translator->batchTranslate($texts, 'en', $languages);

        $this->assertGreaterThan(1, count($translator->requests));

        foreach ($translator->requests as $request) {
            $tokens = 0;

            foreach ($request['keys'] as $key) {
                $tokens += $translator->estimate($key, $texts[$key], count($request['languages']));
            }

            $this->assertLessThanOrEqual(1500, $tokens, 'each batch fits three quarters of the limit');
        }

        $this->assertCount(20, $result);
        $this->assertCount(12, $result['key19']);
    }

    public function test_no_batch_is_ever_larger_than_before()
    {
        $texts = array_fill_keys(array_map(fn ($i) => "k{$i}", range(1, 150)), 'Hi');
        $translator = $this->translator(1_000_000);

        $translator->batchTranslate($texts, 'en', ['es']);

        foreach ($translator->requests as $request) {
            $this->assertLessThanOrEqual(100, count($request['keys']));
        }
    }

    public function test_languages_go_in_groups_when_one_text_into_all_of_them_would_not_fit()
    {
        $translator = $this->translator(400);
        $languages = ['es', 'fr', 'de', 'it', 'pt', 'nl'];

        $result = $translator->batchTranslate(['long' => str_repeat('x', 120)], 'en', $languages);

        $this->assertGreaterThan(1, count($translator->requests));
        $this->assertSame($languages, array_merge(...array_column($translator->requests, 'languages')));
        $this->assertCount(6, $result['long']);
    }

    public function test_a_cut_short_answer_is_split_in_two_and_never_sent_again_whole()
    {
        $texts = ['a' => str_repeat('x', 100), 'b' => str_repeat('y', 100), 'c' => str_repeat('z', 100), 'd' => 'Hi'];
        $translator = $this->translator(100_000, 200);

        $result = $translator->batchTranslate($texts, 'en', ['es']);

        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($result));
        $this->assertTrue($translator->requests[0]['truncated'] ?? false);
        $this->assertSame(['a', 'b', 'c', 'd'], $translator->requests[0]['keys']);
        $this->assertCount(1, array_filter($translator->requests, fn ($r) => $r['keys'] === ['a', 'b', 'c', 'd']), 'the whole batch is sent once');
    }

    public function test_a_single_text_cut_short_splits_its_languages_and_keeps_numeric_keys()
    {
        $translator = $this->translator(100_000, 150);

        $result = $translator->batchTranslate(['404' => str_repeat('x', 60)], 'en', ['es', 'fr', 'de']);

        $this->assertSame(['404'], array_map('strval', array_keys($result)));
        $this->assertSame(['es', 'fr', 'de'], array_keys($result['404']));
    }

    public function test_a_text_that_never_fits_is_skipped_and_the_rest_kept()
    {
        $translator = $this->translator(100_000, 50);

        $result = $translator->batchTranslate(['huge' => str_repeat('x', 400), 'ok' => 'Hi'], 'en', ['es']);

        $this->assertArrayNotHasKey('huge', $result);
        $this->assertSame('[es] Hi', $result['ok']['es']);
    }
}
