<?php

use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Translators\ClaudeTranslator;
use EduLazaro\Laratext\Translators\GoogleTranslator;
use EduLazaro\Laratext\Translators\OpenAITranslator;
use EduLazaro\Laratext\Translators\TranslatorioTranslator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * The same contract for every translator that ships with the package, each against a fake
 * of its own API that answers whatever it is asked, marking each translation with the code
 * the API would use (`[pt-BR] ...` for `pt_BR`, as the real ones do).
 */
class BundledTranslatorsTest extends TestCase
{
    /** @var array<int, array{languages: array<int, string>, keys: array<int, string>}> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'texts.translators.translatorio' => TranslatorioTranslator::class,
            'texts.translatorio' => ['api_key' => 'tr_test_key', 'url' => 'https://translatorio.test', 'retries' => 1],
            'texts.openai.retries' => 1,
            'texts.claude.retries' => 1,
            'texts.google.retries' => 1,
        ]);
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function translators(): array
    {
        return [
            'openai' => [OpenAITranslator::class, 'openai'],
            'claude' => [ClaudeTranslator::class, 'claude'],
            'google' => [GoogleTranslator::class, 'google'],
            'translatorio' => [TranslatorioTranslator::class, 'translatorio'],
        ];
    }

    /**
     * Answers every request the way the provider would, recording what was asked.
     */
    private function fakeApis(int $status = 200): void
    {
        $mark = fn (string $code, string $text) => '[' . str_replace('_', '-', $code) . '] ' . $text;

        Http::fake(function (Request $request) use ($status, $mark) {
            $data = $request->data();

            if ($status !== 200) {
                return Http::response(['error' => ['message' => 'Refused for the test']], $status);
            }

            if (str_contains($request->url(), 'api.openai.com') || str_contains($request->url(), 'api.anthropic.com')) {
                $openAi = str_contains($request->url(), 'openai');
                $system = $openAi ? $data['messages'][0]['content'] : $data['system'][0]['text'];
                $texts = json_decode($openAi ? $data['messages'][1]['content'] : $data['messages'][0]['content'], true);
                preg_match('/multiple languages: (.+?)\. Reply/', $system, $m);
                $languages = array_map('trim', explode(',', $m[1]));
                $this->asked[] = ['languages' => $languages, 'keys' => array_map('strval', array_keys($texts))];

                $answer = [];

                foreach ($texts as $key => $text) {
                    foreach ($languages as $language) {
                        $answer[$key][str_replace('_', '-', $language)] = $mark($language, $text);
                    }
                }

                return $openAi
                    ? Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($answer)]]]])
                    : Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($answer)]]]);
            }

            if (str_contains($request->url(), 'googleapis.com')) {
                $this->asked[] = ['languages' => [$data['target']], 'keys' => []];

                return Http::response(['data' => ['translations' => array_map(
                    fn (string $text) => ['translatedText' => $mark($data['target'], $text)],
                    (array) $data['q'],
                )]]);
            }

            $this->asked[] = ['languages' => $data['targets'], 'keys' => []];

            return Http::response(['results' => array_map(fn (int $index, string $text) => [
                'index' => $index,
                'translations' => array_combine(
                    array_map(fn ($code) => str_replace('_', '-', $code), $data['targets']),
                    array_map(fn ($code) => $mark($code, $text), $data['targets']),
                ),
                'intact' => true,
            ], array_keys($data['texts']), $data['texts'])]);
        });
    }

    /**
     * @dataProvider translators
     */
    public function test_every_key_comes_back_in_every_language_under_the_code_asked_for(string $class)
    {
        $this->fakeApis();

        $result = app($class)->batchTranslate([
            'welcome' => 'Welcome, :name',
            'nav.home' => 'Home',
            '404' => 'Not found',
        ], 'en', ['en', 'es', 'pt_BR']);

        $this->assertSame(['welcome', 'nav.home', '404'], array_map('strval', array_keys($result)));

        foreach (['welcome' => 'Welcome, :name', 'nav.home' => 'Home', '404' => 'Not found'] as $key => $text) {
            $this->assertSame('[es] ' . $text, $result[$key]['es']);
            $this->assertSame('[pt-BR] ' . $text, $result[$key]['pt_BR'], 'filed under pt_BR, whatever the API calls it');
            $this->assertSame($text, $result[$key]['en'], 'the source language gets the source text');
        }
    }

    /**
     * @dataProvider translators
     */
    public function test_the_source_language_is_never_sent(string $class)
    {
        $this->fakeApis();

        app($class)->batchTranslate(['save' => 'Save'], 'en', ['en', 'es']);

        $this->assertNotEmpty($this->asked);

        foreach ($this->asked as $request) {
            $this->assertNotContains('en', $request['languages']);
        }
    }

    /**
     * @dataProvider translators
     */
    public function test_a_refused_request_writes_nothing(string $class)
    {
        $this->fakeApis(400);

        try {
            $result = app($class)->batchTranslate(['save' => 'Save'], 'en', ['es']);
        } catch (RuntimeException $e) {
            // Translatorio stops a scan whose first batch is refused, with the API's message.
            $this->assertStringContainsString('Refused for the test', $e->getMessage());
            $result = [];
        }

        $this->assertArrayNotHasKey('es', $result['save'] ?? []);
    }

    /**
     * @dataProvider translators
     */
    public function test_a_scan_writes_every_language_file(string $class, string $name)
    {
        File::ensureDirectoryExists(lang_path());
        File::ensureDirectoryExists(resource_path('views'));

        foreach (['en', 'es', 'fr'] as $locale) {
            File::delete(lang_path("{$locale}.json"));
        }

        File::put(resource_path('views/test.blade.php'), "@text('pages.home.welcome', 'Welcome, :name') @text('nav.home', 'Home') @text('404', 'Not found')");
        $this->fakeApis();

        $this->artisan("laratext:scan --write --translator={$name}")->assertExitCode(0);

        $es = json_decode(File::get(lang_path('es.json')), true);
        $fr = json_decode(File::get(lang_path('fr.json')), true);
        $en = json_decode(File::get(lang_path('en.json')), true);

        $this->assertSame('[es] Welcome, :name', $es['pages.home.welcome']);
        $this->assertSame('[fr] Home', $fr['nav.home']);
        $this->assertSame('Welcome, :name', $en['pages.home.welcome']);
        $this->assertSame('[es] Not found', $es['404'] ?? null, 'a numeric key is written under itself');
        $this->assertArrayNotHasKey('0', $es);
    }
}
