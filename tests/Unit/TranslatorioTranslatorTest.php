<?php

use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Translators\TranslatorioTranslator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class TranslatorioTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'texts.translatorio.api_key' => 'tr_test_key',
            'texts.translatorio.url' => 'https://translatorio.test',
            'texts.translatorio.retries' => 1,
        ]);
    }

    /**
     * A translator that records its waits instead of sleeping through them.
     */
    private function translator(array &$waits = []): TranslatorioTranslator
    {
        return new class($waits) extends TranslatorioTranslator {
            public function __construct(private array &$waits)
            {
            }

            protected function pause(float $seconds): void
            {
                $this->waits[] = $seconds;
            }
        };
    }

    /**
     * An answer shaped like /v1/translate's, built from what was sent, with the language
     * codes as Translatorio reads them: `pt_BR` becomes `pt-BR`, `zh_CN` becomes `zh`.
     */
    private function fakeApi(int $status = 200): void
    {
        Http::fake(['translatorio.test/*' => function (Request $request) use ($status) {
            if ($status !== 200) {
                return Http::response(['error' => ['code' => 'quota_exhausted', 'message' => 'Out of credits.']], $status);
            }

            $codes = array_map(fn ($code) => match (strtolower(str_replace('_', '-', $code))) {
                'pt-br' => 'pt-BR',
                'zh-cn' => 'zh',
                'zh-tw' => 'zh-TW',
                default => strtolower($code),
            }, $request['targets']);

            $results = [];

            foreach ($request['texts'] as $index => $text) {
                $results[] = [
                    'index' => $index,
                    'translations' => collect($codes)->mapWithKeys(fn ($t) => [$t => "{$t}:{$text}"])->all(),
                    'intact' => true,
                ];
            }

            return Http::response(['id' => 'tr_1', 'targets' => $codes, 'results' => $results, 'intact' => true]);
        }]);
    }

    /** @test */
    public function it_translates_one_text_into_several_languages()
    {
        $this->fakeApi();

        $result = $this->translator()->translate('Hello :name', 'en', ['es', 'fr']);

        $this->assertSame(['es' => 'es:Hello :name', 'fr' => 'fr:Hello :name'], $result);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://translatorio.test/api/v1/translate'
            && $request->hasHeader('Authorization', 'Bearer tr_test_key')
            && $request->hasHeader('Idempotency-Key')
            && $request['source'] === 'en'
            && $request['targets'] === ['es', 'fr']);
    }

    /** @test */
    public function it_maps_a_batch_back_to_its_keys()
    {
        $this->fakeApi();

        $result = $this->translator()->translateMany([
            'nav.home' => 'Home',
            'items.0.name' => 'First',
        ], 'en', ['es']);

        $this->assertSame([
            'nav.home' => ['es' => 'es:Home'],
            'items.0.name' => ['es' => 'es:First'],
        ], $result);

        Http::assertSentCount(1);
    }

    /** @test */
    public function laravel_locale_codes_get_their_translations_back_under_the_same_code()
    {
        $this->fakeApi();

        $result = $this->translator()->translateMany(['cart.empty' => 'Empty'], 'en', ['pt_BR', 'zh_CN', 'zh_TW']);

        $this->assertSame(['pt_BR' => 'pt-BR:Empty', 'zh_CN' => 'zh:Empty', 'zh_TW' => 'zh-TW:Empty'], $result['cart.empty']);
    }

    /** @test */
    public function the_source_language_is_answered_with_the_text_and_never_sent()
    {
        $this->fakeApi();

        $result = $this->translator()->translateMany(['save' => 'Save'], 'en', ['en', 'es']);

        $this->assertSame(['en' => 'Save', 'es' => 'es:Save'], $result['save']);
        Http::assertSent(fn (Request $request) => $request['targets'] === ['es']);

        Http::fake();
        $this->assertSame(['en' => 'Save'], $this->translator()->translate('Save', 'en', ['en']));
        Http::assertNothingSent();
    }

    /** @test */
    public function more_than_twenty_languages_go_in_several_calls()
    {
        $this->fakeApi();
        $languages = ['es', 'fr', 'de', 'it', 'pt', 'nl', 'sv', 'da', 'fi', 'pl', 'cs', 'sk', 'hu', 'ro', 'bg', 'el', 'tr', 'ru', 'uk', 'ja', 'ko', 'ar'];

        $result = $this->translator()->translate('Hi', 'en', $languages);

        $this->assertSame($languages, array_keys($result));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => count($request['targets']) === 20);
        Http::assertSent(fn (Request $request) => $request['targets'] === ['ko', 'ar']);
    }

    /** @test */
    public function texts_with_html_go_as_html_and_the_rest_as_text()
    {
        $this->fakeApi();

        $result = $this->translator()->translateMany([
            'terms' => 'Read the <a href=":url">terms</a>',
            'save' => 'Save',
        ], 'en', ['es']);

        $this->assertSame('es:Read the <a href=":url">terms</a>', $result['terms']['es']);
        $this->assertSame('es:Save', $result['save']['es']);
        Http::assertSent(fn (Request $request) => $request['format'] === 'html' && $request['texts'] === ['Read the <a href=":url">terms</a>']);
        Http::assertSent(fn (Request $request) => $request['format'] === 'text' && $request['texts'] === ['Save']);
    }

    /** @test */
    public function a_format_set_by_hand_is_used_for_every_text()
    {
        $this->fakeApi();
        config(['texts.translatorio.format' => 'text']);

        $this->translator()->translateMany(['terms' => 'Read the <a href="#">terms</a>', 'save' => 'Save'], 'en', ['es']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['format'] === 'text');
    }

    /** @test */
    public function it_sends_the_context_glossary_formality_and_effort()
    {
        $this->fakeApi();

        config([
            'texts.context' => 'A shop for cyclists.',
            'texts.translatorio.glossary' => 'shop',
            'texts.translatorio.formality' => 'informal',
            'texts.translatorio.effort' => 'high',
        ]);

        $this->translator()->translate('Cart', 'en', ['es']);

        Http::assertSent(fn (Request $request) => $request['context'] === 'A shop for cyclists.'
            && $request['glossary'] === 'shop'
            && $request['formality'] === 'informal'
            && $request['effort'] === 'high');
    }

    /** @test */
    public function a_context_longer_than_a_call_takes_is_cut_to_it()
    {
        $this->fakeApi();
        config(['texts.context' => str_repeat('a', 30005)]);

        $this->translator()->translate('Cart', 'en', ['es']);

        Http::assertSent(fn (Request $request) => mb_strlen($request['context']) === 30000);
    }

    /** @test */
    public function out_of_credits_on_the_first_batch_stops_the_scan_and_is_not_retried()
    {
        $this->fakeApi(402);

        try {
            $this->translator()->translateMany(['hello' => 'Hello'], 'en', ['es']);
            $this->fail('A 402 must not be read as a translation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Out of credits.', $e->getMessage());
            $this->assertSame(402, $e->getCode());
        }

        Http::assertSentCount(1);
    }

    /** @test */
    public function out_of_credits_later_keeps_what_was_translated_and_sends_nothing_more()
    {
        $calls = 0;

        Http::fake(['translatorio.test/*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['results' => [['index' => 0, 'translations' => ['es' => 'Hola']]]])
                : Http::response(['error' => ['code' => 'quota_exhausted', 'message' => 'Out of credits.']], 402);
        }]);

        $translator = $this->translator();

        $this->assertSame(['hello' => ['es' => 'Hola']], $translator->translateMany(['hello' => 'Hello'], 'en', ['es']));
        $this->assertSame([], $translator->translateMany(['bye' => 'Bye'], 'en', ['es']));
        $this->assertSame([], $translator->translateMany(['thanks' => 'Thanks'], 'en', ['es']));

        $this->assertSame(2, $calls);
    }

    /** @test */
    public function a_failed_batch_after_a_good_one_is_skipped_not_fatal()
    {
        $calls = 0;

        Http::fake(['translatorio.test/*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['results' => [['index' => 0, 'translations' => ['es' => 'Hola']]]])
                : Http::response(['error' => ['code' => 'invalid_request', 'message' => 'Too long.']], 422);
        }]);

        $translator = $this->translator();
        $translator->translateMany(['hello' => 'Hello'], 'en', ['es']);

        $this->assertSame([], $translator->translateMany(['long' => 'Long'], 'en', ['es']));
        $this->assertSame(2, $calls);
    }

    /** @test */
    public function a_server_error_is_retried_with_the_same_idempotency_key()
    {
        $keys = [];

        Http::fake(['translatorio.test/*' => function (Request $request) use (&$keys) {
            $keys[] = $request->header('Idempotency-Key')[0];

            return count($keys) === 1
                ? Http::response(['error' => ['message' => 'busy']], 503)
                : Http::response(['results' => [['index' => 0, 'translations' => ['es' => 'Hola']]]]);
        }]);

        $this->assertSame(['es' => 'Hola'], $this->translator()->translate('Hello', 'en', ['es']));
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    /** @test */
    public function the_same_call_still_running_is_waited_for_as_the_api_asks()
    {
        $calls = 0;
        $waits = [];

        Http::fake(['translatorio.test/*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['error' => ['code' => 'idempotency_in_flight', 'message' => 'Still running.', 'retry_after' => 1]], 409, ['Retry-After' => '1'])
                : Http::response(['results' => [['index' => 0, 'translations' => ['es' => 'Hola']]]]);
        }]);

        $this->assertSame(['es' => 'Hola'], $this->translator($waits)->translate('Hello', 'en', ['es']));
        $this->assertSame([1.0], $waits);
    }

    /** @test */
    public function a_rate_limit_waits_what_the_api_says_up_to_a_limit()
    {
        $calls = 0;
        $waits = [];
        config(['texts.translatorio.retries' => 2]);

        Http::fake(['translatorio.test/*' => function () use (&$calls) {
            $calls++;

            return match ($calls) {
                1 => Http::response(['error' => ['code' => 'rate_limited', 'retry_after' => 12]], 429, ['Retry-After' => '12']),
                2 => Http::response(['error' => ['code' => 'rate_limited', 'retry_after' => 600]], 429),
                default => Http::response(['results' => [['index' => 0, 'translations' => ['es' => 'Hola']]]]),
            };
        }]);

        $this->translator($waits)->translate('Hello', 'en', ['es']);

        $this->assertSame([12.0, 30.0], $waits);
    }

    /** @test */
    public function an_error_that_retrying_cannot_fix_is_not_retried()
    {
        Http::fake(['translatorio.test/*' => Http::response(['error' => ['code' => 'idempotency_key_reused', 'message' => 'Reused.']], 422)]);

        try {
            $this->translator()->translate('Hello', 'en', ['es']);
            $this->fail('A 422 must not be read as a translation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Reused.', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    /** @test */
    public function a_redirect_or_a_page_that_is_not_json_is_never_a_translation()
    {
        Http::fake(['translatorio.test/*' => Http::response('<html>Login</html>', 200)]);

        $this->expectException(RuntimeException::class);

        $this->translator()->translate('Hello', 'en', ['es']);
    }

    /** @test */
    public function it_says_what_is_missing_without_a_key()
    {
        config(['texts.translatorio.api_key' => null]);

        $this->expectExceptionMessage('TRANSLATORIO_API_KEY');

        $this->translator()->translate('Hello', 'en', ['es']);
    }
}
