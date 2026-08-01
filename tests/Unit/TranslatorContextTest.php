<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Translators\ClaudeTranslator;
use EduLazaro\Laratext\Translators\OpenAITranslator;
use Illuminate\Support\Facades\Http;

class TranslatorContextTest extends TestCase
{
    protected function fakeOpenAi(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode(['save' => ['es' => 'Guardar']])],
                ]],
            ]),
        ]);
    }

    protected function fakeClaude(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['save' => ['es' => 'Guardar']]),
                ]],
            ]),
        ]);
    }

    protected function sentPrompt(): string
    {
        $prompt = '';

        Http::assertSent(function ($request) use (&$prompt) {
            $body = $request->data();

            $prompt = $body['system'] ?? ($body['messages'][0]['content'] ?? '');

            return true;
        });

        return is_array($prompt) ? json_encode($prompt) : $prompt;
    }

    public function test_no_context_is_sent_when_none_is_configured()
    {
        config(['texts.context' => '']);
        $this->fakeOpenAi();

        app(OpenAITranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        $this->assertStringNotContainsString('Context about this application', $this->sentPrompt());
    }

    public function test_openai_receives_the_configured_context()
    {
        config(['texts.context' => 'A real estate CRM. Never translate Inmoqueen.']);
        $this->fakeOpenAi();

        app(OpenAITranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        $prompt = $this->sentPrompt();

        $this->assertStringContainsString('Context about this application', $prompt);
        $this->assertStringContainsString('Never translate Inmoqueen.', $prompt);
    }

    public function test_claude_receives_the_configured_context()
    {
        config(['texts.context' => 'A real estate CRM. Never translate Inmoqueen.']);
        $this->fakeClaude();

        app(ClaudeTranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        $this->assertStringContainsString('Never translate Inmoqueen.', $this->sentPrompt());
    }

    public function test_whitespace_only_context_is_ignored()
    {
        config(['texts.context' => "   \n  "]);
        $this->fakeOpenAi();

        app(OpenAITranslator::class)->translateMany(['save' => 'Save'], 'en', ['es']);

        $this->assertStringNotContainsString('Context about this application', $this->sentPrompt());
    }
}
