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
     * An answer shaped like /v1/translate's, built from what was sent.
     */
    private function fakeApi(int $status = 200): void
    {
        Http::fake(['translatorio.test/*' => function (Request $request) use ($status) {
            if ($status !== 200) {
                return Http::response(['error' => ['code' => 'quota_exhausted', 'message' => 'Out of credits.']], $status);
            }

            $results = [];

            foreach ($request['texts'] as $index => $text) {
                $results[] = [
                    'index' => $index,
                    'translations' => collect($request['targets'])->mapWithKeys(fn ($t) => [$t => "{$t}:{$text}"])->all(),
                    'intact' => true,
                ];
            }

            return Http::response(['id' => 'tr_1', 'results' => $results, 'intact' => true]);
        }]);
    }

    /** @test */
    public function it_translates_one_text_into_several_languages()
    {
        $this->fakeApi();

        $result = (new TranslatorioTranslator())->translate('Hello :name', 'en', ['es', 'fr']);

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

        $result = (new TranslatorioTranslator())->translateMany([
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
    public function it_sends_the_context_glossary_and_formality()
    {
        $this->fakeApi();

        config([
            'texts.context' => 'A shop for cyclists.',
            'texts.translatorio.glossary' => 'shop',
            'texts.translatorio.formality' => 'informal',
        ]);

        (new TranslatorioTranslator())->translate('Cart', 'en', ['es']);

        Http::assertSent(fn (Request $request) => $request['context'] === 'A shop for cyclists.'
            && $request['glossary'] === 'shop'
            && $request['formality'] === 'informal');
    }

    /** @test */
    public function an_out_of_credits_answer_is_an_error_and_is_not_retried()
    {
        $this->fakeApi(402);

        try {
            (new TranslatorioTranslator())->translate('Hello', 'en', ['es']);
            $this->fail('A 402 must not be read as a translation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Out of credits.', $e->getMessage());
        }

        Http::assertSentCount(1);
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

        $this->assertSame(['es' => 'Hola'], (new TranslatorioTranslator())->translate('Hello', 'en', ['es']));
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    /** @test */
    public function a_redirect_or_a_page_that_is_not_json_is_never_a_translation()
    {
        Http::fake(['translatorio.test/*' => Http::response('<html>Login</html>', 200)]);

        $this->expectException(RuntimeException::class);

        (new TranslatorioTranslator())->translate('Hello', 'en', ['es']);
    }

    /** @test */
    public function it_says_what_is_missing_without_a_key()
    {
        config(['texts.translatorio.api_key' => null]);

        $this->expectExceptionMessage('TRANSLATORIO_API_KEY');

        (new TranslatorioTranslator())->translate('Hello', 'en', ['es']);
    }
}
