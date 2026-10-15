<?php

use EduLazaro\Laratext\ModelLimits;
use EduLazaro\Laratext\Tests\TestCase;

class ModelLimitsTest extends TestCase
{
    public function test_the_longest_prefix_wins_and_a_dated_snapshot_finds_its_family()
    {
        config(['texts.models' => [
            'gpt-4' => ['max_output_tokens' => 8192, 'json' => 'none'],
            'gpt-4o' => ['max_output_tokens' => 16384, 'temperature' => true, 'json' => 'schema'],
        ]]);

        $this->assertSame(16384, ModelLimits::for('openai', 'gpt-4o-2024-08-06')->maxOutputTokens);
        $this->assertSame(ModelLimits::JSON_SCHEMA, ModelLimits::for('openai', 'gpt-4o')->json);
        $this->assertSame(8192, ModelLimits::for('openai', 'gpt-4-0613')->maxOutputTokens);
    }

    public function test_a_model_in_no_row_gets_the_cautious_limits()
    {
        $limits = ModelLimits::for('openai', 'some-model-nobody-has-heard-of');

        $this->assertSame(4096, $limits->maxOutputTokens);
        $this->assertFalse($limits->temperature);
        $this->assertFalse($limits->reasoning);
        $this->assertNull($limits->reasoningEffort);
    }

    public function test_a_project_row_corrects_the_package_and_fills_the_rest_from_it()
    {
        config(['texts.models' => ['gpt-9' => ['max_output_tokens' => 200000, 'reasoning' => true, 'reasoning_effort' => 'none']]]);

        $limits = ModelLimits::for('openai', 'gpt-9-turbo');

        $this->assertSame(200000, $limits->maxOutputTokens);
        $this->assertTrue($limits->reasoning);
        $this->assertSame('none', $limits->reasoningEffort);
        $this->assertSame(ModelLimits::JSON_NONE, $limits->json, 'unspecified fields come from the cautious defaults');
    }

    public function test_the_default_models_are_asked_only_for_what_they_take()
    {
        $nano = ModelLimits::for('openai', 'gpt-5.4-nano');
        $this->assertSame([128000, false, ModelLimits::JSON_SCHEMA, false, 'none'], [$nano->maxOutputTokens, $nano->temperature, $nano->json, $nano->reasoning, $nano->reasoningEffort]);

        $gpt5 = ModelLimits::for('openai', 'gpt-5-mini');
        $this->assertSame(['minimal', true, false], [$gpt5->reasoningEffort, $gpt5->reasoning, $gpt5->temperature], 'GPT-5 refuses temperature and cannot skip reasoning');

        $astra = ModelLimits::for('openai', 'gpt-6-astra');
        $this->assertSame('low', $astra->reasoningEffort, 'a GPT-6 that refuses `none` is asked for the least it takes');

        $haiku = ModelLimits::for('claude', 'claude-haiku-4-5');
        $this->assertSame([64000, true, false], [$haiku->maxOutputTokens, $haiku->temperature, $haiku->reasoning]);

        $opus = ModelLimits::for('claude', 'claude-opus-5-5');
        $this->assertSame([128000, false, true], [$opus->maxOutputTokens, $opus->temperature, $opus->reasoning], 'refuses temperature, thinks by default');
    }

    public function test_a_newer_model_of_a_known_family_gets_no_parameter_it_might_refuse()
    {
        $next = ModelLimits::for('openai', 'gpt-5.9');
        $this->assertSame([false, true, null], [$next->temperature, $next->reasoning, $next->reasoningEffort]);

        $this->assertFalse(ModelLimits::for('claude', 'claude-opus-7')->temperature);
        $this->assertSame(ModelLimits::JSON_NONE, ModelLimits::for('openai', 'gpt-7')->json);
    }

    public function test_an_unknown_json_mode_falls_back_to_none()
    {
        config(['texts.models' => ['odd' => ['json' => 'yaml']]]);

        $this->assertSame(ModelLimits::JSON_NONE, ModelLimits::for('openai', 'odd-1')->json);
    }
}
