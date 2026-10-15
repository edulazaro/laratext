<?php

use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Translators\ClaudeTranslator;
use EduLazaro\Laratext\Translators\OpenAITranslator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The OpenAI and Claude translators ask each model only for what it accepts, read the answer
 * under the language codes that were asked for, and split a batch whose answer was cut short.
 */
class ModelAwareRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'texts.openai.retries' => 2,
            'texts.claude.retries' => 2,
            'texts.models' => [
                'reasoner' => ['max_output_tokens' => 128000, 'temperature' => false, 'json' => 'schema', 'reasoning' => true, 'reasoning_effort' => 'minimal'],
                'classic' => ['max_output_tokens' => 16384, 'temperature' => true, 'json' => 'object', 'reasoning' => false],
                'claude-small' => ['max_output_tokens' => 2048, 'temperature' => false],
            ],
        ]);
    }

    private function openAiAnswer(array $content, string $finish = 'stop'): array
    {
        return ['choices' => [['finish_reason' => $finish, 'message' => ['content' => json_encode($content)]]]];
    }

    private function claudeAnswer(array $content, string $stop = 'end_turn'): array
    {
        return ['stop_reason' => $stop, 'content' => [['type' => 'text', 'text' => json_encode($content)]]];
    }

    public function test_a_reasoning_model_gets_no_temperature_its_effort_and_a_strict_schema()
    {
        config(['texts.openai.model' => 'reasoner-1']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiAnswer(['save' => ['es' => 'Guardar']]))]);

        app(OpenAITranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return ! array_key_exists('temperature', $body)
                && $body['reasoning_effort'] === 'minimal'
                && $body['max_completion_tokens'] === 4096 + 25000
                && $body['response_format']['type'] === 'json_schema'
                && $body['response_format']['json_schema']['strict'] === true
                && $body['response_format']['json_schema']['schema']['required'] === ['save']
                && $body['response_format']['json_schema']['schema']['properties']['save']['required'] === ['es'];
        });
    }

    public function test_a_published_config_without_an_effort_still_sends_the_models_own()
    {
        config(['texts.openai.model' => 'reasoner-1', 'texts.openai.reasoning_effort' => null]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiAnswer(['save' => ['es' => 'Guardar']]))]);

        app(OpenAITranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        Http::assertSent(fn (Request $request) => $request->data()['reasoning_effort'] === 'minimal');
    }

    public function test_a_classic_model_keeps_temperature_zero_and_plain_json_mode()
    {
        config(['texts.openai.model' => 'classic-2']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiAnswer(['save' => ['es' => 'Guardar']]))]);

        app(OpenAITranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        Http::assertSent(fn (Request $request) => $request->data()['temperature'] === 0
            && $request->data()['response_format'] === ['type' => 'json_object']
            && ! array_key_exists('reasoning_effort', $request->data()));
    }

    public function test_regional_codes_are_written_under_the_code_asked_for()
    {
        config(['texts.openai.model' => 'classic-2']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiAnswer([
            'welcome' => ['pt-BR' => 'Bem-vindo', 'zh' => '欢迎'],
            'nav' => ['home' => ['pt-BR' => 'Início', 'zh' => '首页']],
        ]))]);

        $result = app(OpenAITranslator::class)->translateMany(['welcome' => 'Welcome', 'nav.home' => 'Home'], 'en', ['pt_BR', 'zh_CN']);

        $this->assertSame(['pt_BR' => 'Bem-vindo', 'zh_CN' => '欢迎'], $result['welcome']);
        $this->assertSame(['pt_BR' => 'Início', 'zh_CN' => '首页'], $result['nav.home'], 'a key the model nested is read back');
    }

    public function test_an_openai_answer_cut_short_splits_the_batch()
    {
        config(['texts.openai.model' => 'classic-2']);
        Http::fakeSequence('api.openai.com/*')
            ->push($this->openAiAnswer(['a' => ['es' => 'Un'], 'b' => ['es' => 'Do']], 'length'))
            ->push($this->openAiAnswer(['a' => ['es' => 'Uno']]))
            ->push($this->openAiAnswer(['b' => ['es' => 'Dos']]));

        $result = app(OpenAITranslator::class)->batchTranslate(['a' => 'One', 'b' => 'Two'], 'en', ['es']);

        $this->assertSame(['a' => ['es' => 'Uno'], 'b' => ['es' => 'Dos']], $result);
        Http::assertSentCount(3);
    }

    public function test_a_refused_request_is_reported_and_not_sent_again()
    {
        config(['texts.openai.model' => 'classic-2']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => "Unsupported parameter: 'temperature'"]], 400)]);

        $result = app(OpenAITranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        $this->assertSame([], $result);
        Http::assertSentCount(1);
    }

    public function test_a_busy_api_is_asked_again()
    {
        config(['texts.openai.model' => 'classic-2']);
        Http::fakeSequence('api.openai.com/*')
            ->push(['error' => ['message' => 'Slow down']], 429)
            ->push($this->openAiAnswer(['save' => ['es' => 'Guardar']]));

        $this->assertSame(['save' => ['es' => 'Guardar']], app(OpenAITranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']));
    }

    public function test_claude_is_asked_within_its_model_limit_and_without_a_refused_temperature()
    {
        config(['texts.claude.model' => 'claude-small-1', 'texts.claude.max_tokens' => 4096]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->claudeAnswer(['save' => ['es' => 'Guardar']]))]);

        app(ClaudeTranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        Http::assertSent(fn (Request $request) => $request->data()['max_tokens'] === 2048
            && ! array_key_exists('temperature', $request->data()));
    }

    public function test_a_claude_model_that_thinks_first_is_read_from_its_text_block()
    {
        config(['texts.claude.model' => 'claude-haiku-5-5']);
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [
            ['type' => 'thinking', 'thinking' => 'Translating two keys into Spanish.', 'signature' => 'x'],
            ['type' => 'text', 'text' => json_encode(['save' => ['es' => 'Guardar']])],
        ]])]);

        $this->assertSame(['save' => ['es' => 'Guardar']], app(ClaudeTranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']));

        Http::assertSent(fn (Request $request) => $request->data()['max_tokens'] === 4096 + 16000, 'room to think on top of the answer');
    }

    public function test_a_claude_answer_cut_short_splits_the_batch()
    {
        config(['texts.claude.model' => 'claude-haiku-4-5']);
        Http::fakeSequence('api.anthropic.com/*')
            ->push($this->claudeAnswer(['a' => ['es' => 'Un']], 'max_tokens'))
            ->push($this->claudeAnswer(['a' => ['es' => 'Uno']]))
            ->push($this->claudeAnswer(['b' => ['es' => 'Dos']]));

        $result = app(ClaudeTranslator::class)->batchTranslate(['a' => 'One', 'b' => 'Two'], 'en', ['en', 'es']);

        $this->assertSame(['en' => 'One', 'es' => 'Uno'], $result['a']);
        $this->assertSame(['en' => 'Two', 'es' => 'Dos'], $result['b']);
        Http::assertSentCount(3);
    }
}
