<?php

use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Translators\ClaudeTranslator;
use EduLazaro\Laratext\Translators\GoogleTranslator;
use EduLazaro\Laratext\Translators\OpenAITranslator;
use EduLazaro\Laratext\Translators\TranslatorioTranslator;

/**
 * Every bundled translator against its real API. These cost money, so they run only when
 * asked for, with the keys in the environment:
 *
 *     LARATEXT_LIVE=1 OPENAI_API_KEY=... ANTHROPIC_API_KEY=... vendor/bin/phpunit tests/Live
 *
 * `LARATEXT_LIVE_OPENAI_MODELS` and `LARATEXT_LIVE_CLAUDE_MODELS` (comma separated) pick the
 * models; `GOOGLE_TRANSLATOR_API_KEY` and `TRANSLATORIO_API_KEY` (with `TRANSLATORIO_URL`)
 * add the other two. A translator without its key is skipped.
 */
class LiveTranslatorsTest extends TestCase
{
    private const TEXTS = [
        'welcome' => 'Welcome back, :name!',
        'cart.items' => 'You have :count items in your cart.',
        'nav.home' => 'Home',
        '404' => 'Page not found',
        'save' => 'Save changes',
        'terms' => 'By signing up you accept our <a href=":url">terms of service</a>.',
    ];

    private const LANGUAGES = ['en', 'es', 'pt_BR', 'ja'];

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string|null}>
     */
    public static function translators(): array
    {
        $cases = [];

        foreach (array_filter(explode(',', (string) getenv('LARATEXT_LIVE_OPENAI_MODELS') ?: 'gpt-5.4-nano')) as $model) {
            $cases["openai {$model}"] = [OpenAITranslator::class, 'openai', trim($model)];
        }

        foreach (array_filter(explode(',', (string) getenv('LARATEXT_LIVE_CLAUDE_MODELS') ?: 'claude-haiku-4-5')) as $model) {
            $cases["claude {$model}"] = [ClaudeTranslator::class, 'claude', trim($model)];
        }

        $cases['google'] = [GoogleTranslator::class, 'google', null];
        $cases['translatorio'] = [TranslatorioTranslator::class, 'translatorio', null];

        return $cases;
    }

    /**
     * @param string $provider
     * @param string|null $model
     * @return void
     */
    private function configure(string $provider, ?string $model): void
    {
        if (getenv('LARATEXT_LIVE') !== '1') {
            $this->markTestSkipped('Live tests run with LARATEXT_LIVE=1.');
        }

        $key = [
            'openai' => 'OPENAI_API_KEY',
            'claude' => 'ANTHROPIC_API_KEY',
            'google' => 'GOOGLE_TRANSLATOR_API_KEY',
            'translatorio' => 'TRANSLATORIO_API_KEY',
        ][$provider];

        if (! getenv($key)) {
            $this->markTestSkipped("{$key} is not set.");
        }

        config([
            "texts.{$provider}.api_key" => getenv($key),
            "texts.{$provider}.timeout" => 180,
            "texts.{$provider}.retries" => 2,
        ]);

        if ($model !== null) {
            config(["texts.{$provider}.model" => $model]);
        }

        if ($provider === 'translatorio') {
            config(['texts.translatorio.url' => getenv('TRANSLATORIO_URL') ?: 'https://translatorio.com']);
        }
    }

    /**
     * @dataProvider translators
     */
    public function test_a_batch_comes_back_whole_with_its_placeholders(string $class, string $provider, ?string $model)
    {
        $this->configure($provider, $model);

        $started = microtime(true);
        $result = app($class)->batchTranslate(self::TEXTS, 'en', self::LANGUAGES);

        fwrite(STDERR, "\n--- {$provider} " . ($model ?? '') . ' (' . round(microtime(true) - $started, 1) . " s)\n" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

        foreach (self::TEXTS as $key => $text) {
            $this->assertSame($text, $result[$key]['en'] ?? null, "[{$key}] en is the source text");

            foreach (['es', 'pt_BR', 'ja'] as $language) {
                $translation = $result[$key][$language] ?? null;

                $this->assertIsString($translation, "[{$key}] has no {$language}");
                $this->assertNotSame('', trim($translation), "[{$key}] {$language} is empty");

                preg_match_all('/:[a-z]+/', $text, $placeholders);

                foreach ($placeholders[0] as $placeholder) {
                    $this->assertStringContainsString($placeholder, $translation, "[{$key}] {$language} lost {$placeholder}");
                }
            }
        }

        $this->assertNotSame(self::TEXTS['save'], $result['save']['es'], 'es is translated, not copied');
    }

    /**
     * Twenty languages against a small answer: the batches and the language groups have to
     * fit the real model, and everything still comes back.
     *
     * @dataProvider translators
     */
    public function test_many_languages_on_a_small_answer_still_come_back_whole(string $class, string $provider, ?string $model)
    {
        if (! in_array($provider, ['openai', 'claude'], true)) {
            $this->markTestSkipped('Only the model translators size their answers.');
        }

        $this->configure($provider, $model);
        config(['texts.openai.max_output_tokens' => 600, 'texts.claude.max_tokens' => 600]);

        $languages = ['es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'sv', 'da', 'fi', 'cs', 'ro', 'hu', 'el', 'tr', 'ru', 'uk', 'ja', 'ko', 'zh_CN'];
        $texts = ['save' => 'Save changes', 'cart.items' => 'You have :count items in your cart.', 'nav.home' => 'Home'];

        $result = app($class)->batchTranslate($texts, 'en', $languages);

        foreach ($texts as $key => $text) {
            foreach ($languages as $language) {
                $this->assertIsString($result[$key][$language] ?? null, "[{$key}] has no {$language}");
            }

            foreach ($languages as $language) {
                if ($key === 'cart.items') {
                    $this->assertStringContainsString(':count', $result[$key][$language], "[{$key}] {$language} lost :count");
                }
            }
        }
    }
}
