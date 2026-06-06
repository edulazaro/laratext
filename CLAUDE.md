# Laratext - Notes for Claude

## What this package does

Laratext is a Laravel package for managing and auto-translating text strings. Devs use `text('key', 'value')` (PHP) or `@text('key', 'value')` (Blade) to declare both a key and its source text in one call. The `laratext:scan` command extracts those calls from the codebase and writes per-locale JSON files (`lang/{locale}.json`), translating missing or drifted source texts via a configurable translator (OpenAI, Claude, Google, or custom).

## Repo layout

- `src/Commands/ScanTranslationsCommand.php`: the main `laratext:scan` Artisan command. All scan logic (extracting keys, comparing with lang files, detecting drift/orphans, calling the translator, writing JSON) lives here.
- `src/Translators/`: built-in translators (`OpenAITranslator`, `ClaudeTranslator`, `GoogleTranslator`). New translators implement `Contracts/TranslatorInterface` and extend `Translator` (which provides `logToConsole`).
- `src/Contracts/TranslatorInterface.php`: minimal contract requiring `translate(string $text, string $from, array $to): array`. Optionally `translateMany()` and `batchTranslate()` for batch APIs.
- `config/texts.php`: published config. Defines `default_translator`, the translator map, per-translator config blocks, and the supported `languages`.
- `tests/`: PHPUnit 10 tests using Orchestra Testbench. `TestCase.php` registers the package and sets up a fake config with three languages (en, es, fr) and fake API keys.
- `tests/Fixtures/`: PHP and Blade files used by the extractor tests.

## Style and conventions

- **No em-dashes (—) in markdown docs.** Use a colon, parentheses, comma, or period instead. The user finds em-dashes a stylistic tic of AI writing.
- **Default to no comments in code.** Only add a comment when the WHY is non-obvious.
- **Don't create .md files unless asked.** README and CHANGELOG are explicitly maintained; do not invent new docs files.
- **Tests use the `/** @test */` convention plus `it_*` method names.** Match existing patterns when adding tests.
- **HTTP calls go through `Illuminate\Support\Facades\Http`** for consistency with existing translators and to keep `Http::fake()` working in tests.

## Tests

Run the full suite:

```bash
vendor/bin/phpunit
```

Run a single class:

```bash
vendor/bin/phpunit tests/Unit/ScanTranslationsCommandTest.php
```

### Test isolation gotcha

The test suite has three configured languages (`en`, `es`, `fr`). Tests that don't write a `fr.json` will trigger the scanner to mark every key as missing in French and call the translator (causing `Http::assertNothingSent()` to fail). When adding tests, write JSON files for **all three** languages, or use `--lang=es` to scope the run (be aware that `--lang` with a non-default locale has a known edge case with stale detection because the default-language JSON isn't loaded).

The `setUp` and `tearDown` in `ScanTranslationsCommandTest.php` clean up `en.json`, `es.json`, and `fr.json`. If you add a fourth language to a test, clean it up too.

## Scan command semantics (post-2.0)

The `laratext:scan` command has three flags that control what gets translated:

- **No flag (default):** translates new keys + retranslates drifted keys (where the source text in code differs from the value stored in `lang/{defaultLocale}.json`).
- **`--only-missing`:** translates only new keys. Drift is detected and reported as a warning, but no API calls are made for it. This was the pre-2.0 default behaviour.
- **`--resync`:** retranslates **every** key from scratch, ignoring existing translations. Use after switching translator or model.
- **`--prune`:** detects keys present in lang files but no longer referenced in code. By default lists them; combined with `--write` removes them from every configured language file.

These can combine: `laratext:scan --write --prune` is the typical periodic cleanup recipe. `laratext:scan --write --resync` is the "full regeneration" recipe.

## Translators

### Built-in translators

| Name     | Class                  | Default model         | Endpoint                                      |
| -------- | ---------------------- | --------------------- | --------------------------------------------- |
| `openai` | `OpenAITranslator`     | `gpt-5.4-nano`        | `https://api.openai.com/v1/chat/completions`  |
| `claude` | `ClaudeTranslator`     | `claude-haiku-4-5`    | `https://api.anthropic.com/v1/messages`       |
| `google` | `GoogleTranslator`     | (Google Translate v2) | Google Cloud Translation API                  |

OpenAI is the default. Both `OpenAITranslator` and `ClaudeTranslator` send a system prompt asking for a flat JSON object with translations indexed by language code; both have a retry loop that handles invalid JSON responses. `ClaudeTranslator` additionally:

- Uses the Messages API top-level `system` field with a `cache_control: ephemeral` block so repeated batches in a scan run benefit from prompt caching.
- Strips markdown code fences (` ```json ... ``` `) from responses, since Claude occasionally wraps JSON output despite being told not to.

### Adding a new translator

1. Create `src/Translators/MyTranslator.php` extending `Translator` and implementing `TranslatorInterface`.
2. Implement `translate()` and ideally `translateMany()` (batch is much cheaper for large scans).
3. Add `'mykey' => MyTranslator::class` to the translators map in `config/texts.php` and the matching block in `tests/TestCase.php` so tests can resolve it.
4. Add a `tests/Unit/MyTranslatorTest.php` mirroring `OpenAITranslatorTest.php` or `ClaudeTranslatorTest.php`.

## Key extraction

The extractor in `ScanTranslationsCommand::extractTextsFromFiles()` recognises:

- Two-arg calls: `text('key', 'value')`, `@text('key', 'value')`, `Text::get('key', 'value')`. The second arg becomes the source text.
- One-arg calls: `text('key')`, `@text('key');`, `Text::get('key');`. The source text is auto-generated via `keyToText()` (last dot segment, underscores to spaces, title-cased: `pages.contact_us` becomes `"Contact Us"`).

This auto-generation has implications for drift detection: a key declared as `text('hello_mate')` produces a synthetic source text. If the dev later edits the JSON manually to a real translation, the auto-generated value won't match, but the extractor compares against the auto-generated value, not the edited one. The current behaviour is acceptable because devs who care about drift use the two-arg form.

## Known quirks

- `--lang=X` only loads `existingTranslations[X]`, not the default-language JSON. Stale detection relies on `existingTranslations[$defaultLanguage]`, so when `X != defaultLanguage`, stale detection silently does nothing for that run. Not a regression introduced in 2.0 (was already there). Document/fix later if it bites someone.
- `Http::fake()` without a pattern in tests returns empty responses but still records requests. Use `Http::fake(['api.openai.com/*' => ...])` to scope, and `Http::assertNothingSent()` to verify no calls were made.
