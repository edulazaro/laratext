<?php

namespace EduLazaro\Laratext;

/**
 * What a model can answer and which request parameters it takes.
 *
 * Translating with an old model is not translating with a new one: one answers 4,096 tokens
 * at most and another 128,000, one takes `temperature` and another refuses the request if it
 * is sent, one can be held to a JSON schema and another only asked nicely. The translators
 * read this to size their batches and shape their requests.
 *
 * The table is matched by the longest prefix of the model's name, so a dated snapshot such as
 * `gpt-4o-2024-08-06` finds `gpt-4o`. A model in no row gets the most cautious limits. A
 * project can correct or add a row in `texts.models` without waiting for a release:
 *
 *     'models' => [
 *         'gpt-5.9' => ['max_output_tokens' => 128000, 'temperature' => false, 'json' => 'schema', 'reasoning_effort' => 'none'],
 *     ],
 */
final class ModelLimits
{
    /** The answer can be held to a JSON schema (OpenAI's Structured Outputs). */
    public const JSON_SCHEMA = 'schema';

    /** The answer is guaranteed to be JSON, but not to follow a schema. */
    public const JSON_OBJECT = 'object';

    /** Nothing can be asked of the format: the prompt is all there is. */
    public const JSON_NONE = 'none';

    /**
     * @param int $maxOutputTokens The most tokens one answer may hold.
     * @param bool $temperature Whether `temperature` may be sent.
     * @param string $json One of the JSON_ constants.
     * @param bool $reasoning Whether the model thinks before answering, spending output tokens.
     * @param string|null $reasoningEffort The effort to ask for, or null to send none.
     */
    public function __construct(
        public readonly int $maxOutputTokens,
        public readonly bool $temperature,
        public readonly string $json,
        public readonly bool $reasoning,
        public readonly ?string $reasoningEffort,
    ) {
    }

    /**
     * Provider => model prefix => [max output tokens, temperature, json, reasoning, effort].
     *
     * Read from OpenAI's and Anthropic's model pages on 2026-10-15. `temperature` is sent only
     * where it is accepted for certain: GPT-5 refuses it, GPT-5.1 and later take it only at no
     * reasoning, and Claude Opus 4.7 and later refuse anything but the default. OpenAI models
     * that can skip reasoning are asked for `none`, the rest for the least they take; Claude
     * models that think by default are marked as reasoning, so room is left for it. Retired
     * models (Claude 3, Claude 4 and 4.1) are left out: no request to them succeeds.
     *
     * @var array<string, array<string, array{0: int, 1: bool, 2: string, 3: bool, 4: string|null}>>
     */
    private const TABLE = [
        'openai' => [
            'gpt-3.5-turbo' => [4096, true, self::JSON_OBJECT, false, null],
            'gpt-4' => [8192, true, self::JSON_NONE, false, null],
            'gpt-4-turbo' => [4096, true, self::JSON_OBJECT, false, null],
            'gpt-4-1106' => [4096, true, self::JSON_OBJECT, false, null],
            'gpt-4-0125' => [4096, true, self::JSON_OBJECT, false, null],
            'gpt-4o' => [16384, true, self::JSON_SCHEMA, false, null],
            'gpt-4o-2024-05-13' => [4096, true, self::JSON_OBJECT, false, null],
            'gpt-4o-mini' => [16384, true, self::JSON_SCHEMA, false, null],
            'gpt-4.1' => [32768, true, self::JSON_SCHEMA, false, null],
            'o1' => [100000, false, self::JSON_SCHEMA, true, null],
            'o1-mini' => [65536, false, self::JSON_NONE, true, null],
            'o3' => [100000, false, self::JSON_SCHEMA, true, null],
            'o4-mini' => [100000, false, self::JSON_SCHEMA, true, null],
            'gpt-5' => [128000, false, self::JSON_SCHEMA, true, 'minimal'],
            'gpt-5.' => [128000, false, self::JSON_SCHEMA, true, null],
            'gpt-5.1' => [128000, false, self::JSON_SCHEMA, false, 'none'],
            'gpt-5.2' => [128000, false, self::JSON_SCHEMA, false, 'none'],
            'gpt-5.4' => [128000, false, self::JSON_SCHEMA, false, 'none'],
            'gpt-5.5' => [128000, false, self::JSON_SCHEMA, false, 'none'],
            'gpt-5.6' => [128000, false, self::JSON_SCHEMA, false, 'none'],
            'gpt-6' => [128000, false, self::JSON_SCHEMA, true, 'low'],
            'gpt-6-sol' => [128000, false, self::JSON_SCHEMA, false, 'none'],
            'gpt-6-luna' => [128000, false, self::JSON_SCHEMA, false, 'none'],
        ],
        'claude' => [
            'claude-sonnet-4-5' => [64000, true, self::JSON_NONE, false, null],
            'claude-haiku-4-5' => [64000, true, self::JSON_NONE, false, null],
            'claude-opus-4-5' => [64000, true, self::JSON_NONE, false, null],
            'claude-opus-4-6' => [128000, true, self::JSON_NONE, false, null],
            'claude-sonnet-4-6' => [128000, true, self::JSON_NONE, false, null],
            'claude-opus-4-7' => [128000, false, self::JSON_NONE, false, null],
            'claude-opus-4-8' => [128000, false, self::JSON_NONE, false, null],
            'claude-opus-5' => [128000, false, self::JSON_NONE, false, null],
            'claude-sonnet-5' => [128000, false, self::JSON_NONE, false, null],
            'claude-fable-5' => [128000, false, self::JSON_NONE, false, null],
            'claude-mythos-5' => [128000, false, self::JSON_NONE, false, null],
            'claude-opus-5-5' => [128000, false, self::JSON_NONE, true, null],
            'claude-sonnet-5-5' => [128000, false, self::JSON_NONE, true, null],
            'claude-haiku-5-5' => [128000, false, self::JSON_NONE, true, null],
            'claude-fable-5-1' => [128000, false, self::JSON_NONE, true, null],
            'claude-mythos-5-1' => [128000, false, self::JSON_NONE, true, null],
        ],
    ];

    /**
     * What a model in no row gets: a short answer and nothing it might refuse, which is how
     * every model was asked before this table existed, minus `temperature`.
     *
     * @var array<string, array{0: int, 1: bool, 2: string, 3: bool, 4: string|null}>
     */
    private const UNKNOWN = [
        'openai' => [4096, false, self::JSON_NONE, false, null],
        'claude' => [4096, false, self::JSON_NONE, false, null],
    ];

    /**
     * The limits of a model, from the table and the project's `texts.models`.
     *
     * @param string $provider `openai` or `claude`.
     * @param string $model
     * @return self
     */
    public static function for(string $provider, string $model): self
    {
        $rows = array_map(
            static fn (array $row) => [
                'max_output_tokens' => $row[0],
                'temperature' => $row[1],
                'json' => $row[2],
                'reasoning' => $row[3],
                'reasoning_effort' => $row[4],
            ],
            self::TABLE[$provider] ?? [],
        );

        // A project's own rows, by name or prefix, over the package's.
        foreach ((array) config('texts.models', []) as $prefix => $row) {
            if (is_array($row)) {
                $rows[(string) $prefix] = $row + ($rows[(string) $prefix] ?? []);
            }
        }

        $unknown = self::UNKNOWN[$provider] ?? self::UNKNOWN['openai'];
        $row = [
            'max_output_tokens' => $unknown[0],
            'temperature' => $unknown[1],
            'json' => $unknown[2],
            'reasoning' => $unknown[3],
            'reasoning_effort' => $unknown[4],
        ];

        $matched = null;

        foreach (array_keys($rows) as $prefix) {
            if (str_starts_with($model, (string) $prefix) && strlen((string) $prefix) > strlen((string) $matched)) {
                $matched = (string) $prefix;
            }
        }

        if ($matched !== null) {
            $row = $rows[$matched] + $row;
        }

        return new self(
            maxOutputTokens: max(1, (int) $row['max_output_tokens']),
            temperature: (bool) $row['temperature'],
            json: in_array($row['json'], [self::JSON_SCHEMA, self::JSON_OBJECT, self::JSON_NONE], true) ? $row['json'] : self::JSON_NONE,
            reasoning: (bool) $row['reasoning'],
            reasoningEffort: isset($row['reasoning_effort']) ? (string) $row['reasoning_effort'] : null,
        );
    }
}
