# Changelog

All notable changes to `laratext` will be documented in this file.

## 2.2.0

Nothing to change in an existing project: a published `config/texts.php` keeps working as it is, and a translator of your own that extends `Translator` behaves exactly as before.

### Added

- The OpenAI and Claude translators know what each model can do (`EduLazaro\Laratext\ModelLimits`, read from both providers' model pages): the longest answer it may give, whether it accepts `temperature`, whether its answer can be held to a JSON schema and whether it reasons first. A model it does not know is asked the way every model was before, minus `temperature`. Correct a model or add one under `models` in `config/texts.php`, by name or prefix.
- Batches sized by the answer they produce. A batch into twenty languages answers twenty times its size, so batches are cut to fit one answer (`texts.openai.max_output_tokens`, new, and `texts.claude.max_tokens`, 4,096 by default, capped by the model's limit), and languages go in groups when one string into all of them would not fit. No batch is larger than the 2,000 characters and 100 keys of before. A translator of your own opts in by returning a number from `outputTokenLimit()`.
- Reasoning models get `reasoning_effort` (`none` where the model can skip reasoning, otherwise the least it takes; `texts.openai.reasoning_effort` changes it) and room on top of the answer for the reasoning, which counts against the same allowance.
- OpenAI models with Structured Outputs answer against a schema of exactly the keys and languages asked for; older ones in JSON mode.

### Fixed

- An answer cut short at the output limit is split in two until it fits. It was sent again whole, cut at the same place and paid for each time, up to three, and then the batch was dropped.
- Regional codes such as `pt_BR` and `zh_CN` lost their translations with OpenAI and Claude: the answer was read as a nested key. They are written under the code asked for, whether the model answers `pt-BR` or `zh`.
- The source language is no longer sent to OpenAI or Claude: its file gets the source text instead of a translation of it into itself.
- `temperature` is no longer sent to the models that refuse it, where every request failed: GPT-5, the GPT-5.x and GPT-6 models that reason by default, and Claude Opus 4.7 and later.
- A refused request (a 400, a bad key) is reported with the API's message and not sent again; a 429 or a 5xx is asked again. A failed request used to pass for an empty answer.
- The OpenAI batch timeout defaulted to 10 seconds when the config did not set one; it is 60, like the rest.
- A numeric key such as `404` was written back as `0` by Google, Translatorio and any translator of your own: the batches were merged with `array_merge_recursive`, which renumbers integer keys.
- Google Translate: Laravel codes such as `pt_BR` are sent as `pt-BR`, which is what Google reads (and as the base language when Google does not know the region); the source language is no longer sent; a key named `0` is no longer dropped; and a refused request is reported and skipped instead of retried and thrown, a 429 or a 5xx asked again.
- Claude models that think before answering (Claude Haiku 5.5 and the rest of the 5.5 family) were read from the thinking block instead of the answer, so every batch came back empty.

### Tests

- One contract for the four bundled translators (`BundledTranslatorsTest`): every key in every language under the code asked for, the source language never sent, a refused request writing nothing, and a whole `laratext:scan --write` with each.
- `tests/Live` runs the same against the real APIs, only when asked for (`LARATEXT_LIVE=1` and the keys); it is skipped otherwise, in CI included. Checked on 2026-10-15 with gpt-5.4-nano, gpt-4o-mini, gpt-5-nano, gpt-5.6-luna, claude-haiku-4-5, claude-haiku-5-5 and claude-sonnet-4-6.

- A Translatorio translator (`--translator=translatorio`, `TRANSLATORIO_API_KEY`). [Translatorio](https://translatorio.com) is a translation API that takes placeholders (`:name`, `{count}`, `%s`), HTML tags and links out of each string before the model reads it, puts them back, and reports any that did not survive: the scan prints a warning for that key instead of writing a broken string.

  ```bash
  php artisan laratext:scan --write --translator=translatorio
  ```

  Its options in `config/texts.php`, under `translatorio`: `glossary` (the slug of a glossary on your account), `formality` (`default`, `formal` or `informal`), `effort` (`default`, `low`, `medium` or `high`, which model translates) and `format` (`auto`, the default, sends strings with HTML tags as `html` so their tags are protected too, and the rest as `text`).

  - Laravel locale codes such as `pt_BR` or `zh_CN` are written back under the same code.
  - The source language is never sent: its file gets the source text.
  - More than twenty languages go in several calls, and `context` is sent with every batch, up to 30,000 characters.
  - Retries on a `429`, a `5xx` or a call still running after a timeout reuse the same `Idempotency-Key`, so a retried batch is never charged twice, and wait as long as the API asks.
  - A `402` (out of credits) is never retried. On the first batch it stops the scan with the API's message; later on, what was already translated is kept and written, nothing more is sent, and the next scan finishes the rest. Any other batch that fails after a good one is skipped and reported, as the other translators do.

## 2.1.3

### Added

- `texts.source_locale`, the language the texts in your code are written in. It is what the scanner compares against and what the translator is told to translate from. Optional and empty by default, in which case `config('app.locale')` keeps being used, so nothing changes for a project that does not set it.

  It matters when the two differ, for example an application running with `APP_LOCALE=es` whose source texts are written in English. There, `@text('portfolio.heading', 'Portfolio')` was compared against the Spanish "Portafolio", so every key looked like its source text had changed and a single `--write` would retranslate the entire project.

### Fixed

- Keys built with string interpolation are skipped instead of corrupting the scan. Only a literal key can be scanned, and a double-quoted key such as `text("activity.{$type->value}", $default)` is not one. The pattern used to match past the intended closing quote and swallow the rest of the file, so a single call like that produced one enormous key containing PHP source, and every legitimate `text()` call inside the swallowed span was lost: those keys silently stopped being created and translated.

  A key is now discarded only when it is genuinely interpolated, meaning a double-quoted string containing `$`. Single quotes never interpolate in PHP, so `text('hol$a')` stays a valid key, and the rule applies to the key alone: the default text and the replacements are untouched.

## 2.1.2

### Fixed

- `--dry` now compares the code with the language files and reports what a real run would do: how many keys are new, how many have a changed source text and how many are orphans. It used to list every key found in the code under the heading "these keys would be added", which in a project with everything already translated meant thousands of lines saying nothing. It still writes nothing and still never calls the translator, and it now combines with `--only-missing`, `--lang` and the rest.

## 2.1.1

### Added

- Locked keys. A key can be marked so the scanner never writes it again, which protects a translation reviewed by a human from being overwritten on the next run.

  ```bash
  php artisan laratext:lock save                 # in every configured language
  php artisan laratext:lock save --lang=es       # only in Spanish
  php artisan laratext:lock "errors.*"           # wildcards are allowed
  php artisan laratext:lock --all --lang=es      # every key currently in es.json

  php artisan laratext:unlock save --lang=es
  php artisan laratext:unlock --all --lang=es    # asks for confirmation
  php artisan laratext:unlock --all --force      # no question, for scripts
  ```

  A locked key is not retranslated when its source text drifts, is not touched by `--resync`, and is not removed by `--prune`. Locks are per language, so protecting a Spanish correction still lets the other languages follow the English source. A key locked in every language is never sent to the translator, which also saves tokens.

  Locks live in `lang/.locked/{locale}.json` as a plain list of keys, meant to be committed. Nothing is locked unless you lock it: with no lock files the scanner behaves exactly as before, so upgrading changes nothing until you use it.

- Plural forms. Separate them with `|` and pass the quantity as `count`, the same syntax Laravel uses, and `text()` chooses the form instead of returning the whole string:

  ```php
  text('items.count', 'One item|You have :count items', ['count' => 5]);
  // "You have 5 items"
  ```

  The choice is made by Laravel's own `MessageSelector`, so the plural rules of each language apply and a language with three forms, such as Russian or Polish, uses three. Explicit ranges (`{0} ...|[1,19] ...|[20,*] ...`) work too, and forms are honoured both in the translated file and in the source text of a key that has not been scanned yet.

  Two signals are required, a `|` in the text and a numeric `count` in the replacements, so a text that merely contains a pipe keeps being returned untouched. Previously `text('items.count', 'You have :count items', ['count' => 1])` produced "You have 1 items", and a text written with `|` was returned with the pipe inside.

- `texts.context`, an optional description of the application that is sent to the translator with every batch, so it knows what it is translating instead of guessing from short strings on their own. Use it for what the application does, the register you want, and the terms that must not be translated. Empty by default, and ignored by translators that take no prompt, such as Google Translate.
- The prompt now states that translation keys may be used as context. They already travelled to the translator with their text, but nothing said they carried meaning, so `nav.home` had no more weight than any other identifier when choosing between "Inicio" and "Hogar".

## 2.1.0

### Added

- Continuous integration covering PHP `8.2` to `8.5` against Laravel `10`, `11`, `12` and `13`, plus PHP nightly. The matrix is resolved at run time from the active PHP branches and the published Laravel majors, so a new release joins it without touching the workflow.

### Changed

- The distributed package no longer ships `tests`, `phpunit.xml` or the CI workflow, so installing it puts fewer files in your `vendor` directory. They are still in the repository.
- `guzzlehttp/guzzle` is now an explicit dev dependency. The test suite already needed it and it was only arriving as a transitive dependency of newer Testbench versions.

## 2.0.0

### BREAKING

- `laratext:scan --write` now retranslates keys whose source text in code has drifted from the value stored in `lang/{defaultLocale}.json`, in addition to translating brand-new keys. Previously, drift was silently ignored unless `--resync` was passed. If you rely on the old behaviour (translate only brand-new keys, leave drifted keys alone), pass the new `--only-missing` flag.
- `--resync` semantics changed: it now retranslates **every** key in your codebase from scratch, ignoring existing translations. Previously it retranslated only keys whose source text had drifted. Intended for one-off full regenerations (e.g. after switching translator or model).

### Added

- `--only-missing` flag: skip drifted keys and only translate brand-new ones. Drift is still reported as a warning. Restores the pre-2.0 default behaviour for teams that prefer it.
- `--prune` flag: lists keys present in `lang/{locale}.json` files but no longer found in code. Combined with `--write`, removes them from every configured language file.
- Drift detection now reports old vs. new source text for each affected key, so you can see exactly what changed.
- `ClaudeTranslator` for translating via Anthropic's Messages API. Defaults to `claude-haiku-4-5` (override with `ANTHROPIC_MODEL`). Prompt caching is enabled on the system prompt by default, so repeated batches in a single scan run benefit from cached instructions. Select it per-run with `--translator=claude` or set it as the default in `config/texts.php`.

### Changed

- Default OpenAI model bumped from the legacy `gpt-3.5-turbo` to `gpt-5.4-nano`, currently OpenAI's cheapest and fastest small model, well-suited for low-temperature JSON translation work. Override via the `OPENAI_MODEL` env var if you prefer a different one.

### Migration

| Before (1.x)                                  | After (2.0)                                           |
| --------------------------------------------- | ----------------------------------------------------- |
| `laratext:scan --write`                       | `laratext:scan --write --only-missing` (same result)  |
| `laratext:scan --write --resync`              | `laratext:scan --write` (drifted keys retranslated)   |
| (no equivalent)                               | `laratext:scan --write --resync` (retranslate all)    |
