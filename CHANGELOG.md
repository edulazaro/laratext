# Changelog

All notable changes to `laratext` will be documented in this file.

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
