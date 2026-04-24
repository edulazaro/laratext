# Changelog

All notable changes to `laratext` will be documented in this file.

## 2.0 — Unreleased

### BREAKING

- `laratext:scan --write` now retranslates keys whose source text in code has drifted from the value stored in `lang/{defaultLocale}.json`, in addition to translating brand-new keys. Previously, drift was silently ignored unless `--resync` was passed. If you rely on the old behaviour (translate only brand-new keys, leave drifted keys alone), pass the new `--only-missing` flag.
- `--resync` semantics changed: it now retranslates **every** key in your codebase from scratch, ignoring existing translations. Previously it retranslated only keys whose source text had drifted. Intended for one-off full regenerations (e.g. after switching translator or model).

### Added

- `--only-missing` flag: skip drifted keys and only translate brand-new ones. Drift is still reported as a warning. Restores the pre-2.0 default behaviour for teams that prefer it.
- `--prune` flag: lists keys present in `lang/{locale}.json` files but no longer found in code. Combined with `--write`, removes them from every configured language file.
- Drift detection now reports old vs. new source text for each affected key, so you can see exactly what changed.
- `ClaudeTranslator` for translating via Anthropic's Messages API. Defaults to `claude-haiku-4-5` (override with `ANTHROPIC_MODEL`). Prompt caching is enabled on the system prompt by default, so repeated batches in a single scan run benefit from cached instructions. Select it per-run with `--translator=claude` or set it as the default in `config/texts.php`.

### Changed

- Default OpenAI model bumped from the legacy `gpt-3.5-turbo` to `gpt-5.4-nano` — currently OpenAI's cheapest and fastest small model, well-suited for low-temperature JSON translation work. Override via the `OPENAI_MODEL` env var if you prefer a different one.

### Migration

| Before (1.x)                                  | After (2.0)                                           |
| --------------------------------------------- | ----------------------------------------------------- |
| `laratext:scan --write`                       | `laratext:scan --write --only-missing` (same result)  |
| `laratext:scan --write --resync`              | `laratext:scan --write` (drifted keys retranslated)   |
| (no equivalent)                               | `laratext:scan --write --resync` (retranslate all)    |
