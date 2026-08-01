![Laratext](art/banner.png)

# Laratext for Laravel

<p align="center">
    <a href="https://github.com/edulazaro/laratext/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/laratext/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/laratext"><img src="https://img.shields.io/packagist/v/edulazaro/laratext" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/laratext"><img src="https://img.shields.io/packagist/dt/edulazaro/laratext" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/laratext"><img src="https://img.shields.io/packagist/php-v/edulazaro/laratext" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/laratext/blob/main/LICENSE.md"><img src="https://img.shields.io/packagist/l/edulazaro/laratext" alt="License"></a>
</p>


## Introduction

Laratext is a Laravel package designed to manage and auto-translate your application's text strings. In laravel, when using the `__` gettext helper method you specify the translation or the key. Both options have issues. If you specify the key, the file becomes difficult to read, as you don't know what's there. If you specify the text, your translations will break if you change a single character. With Laratext you specify both the key and the text, making it useful and readable.

It also allows you to seamlessly integrate translation services (like OpenAI or Google Translate) into your Laravel application to automatically translate missing translation keys across multiple languages.

It includes these features:

* Simplifies working with language files in Laravel.
* Auto-translate missing translation keys to multiple languages.
* Supports multiple translation services (e.g., OpenAI, Google Translate).
* Easy-to-use Blade directive (@text) and helper functions (text()).
* Commands to scan and update translation files.

## Requirements

- PHP `8.2`, `8.3`, `8.4` and `8.5`
- Laravel `10`, `11`, `12` and `13`

Every combination of those is covered by the test suite on each push, and once a week so a new release that breaks the package shows up here first.

## Installation

Execute the following command in your Laravel root project directory:

```bash
composer require edulazaro/laratext
```

To publish the configuration run:

```bash
php artisan vendor:publish --tag="texts"
```

Or if for some reason it does not work:

```bash
php artisan vendor:publish --provider="EduLazaro\Laratext\LaratextServiceProvider" --tag="texts"
```

This will generate the `texts.php` configuration file in the `config` folder.

## Configuration

The `texts.php` configuration file contains all the settings for the package, including API keys for translation services, supported languages, and more.

Example of the configuration (`config/texts.php`):

```php
return [
    // Default Translator
    'default_translator' => EduLazaro\Laratext\Translators\OpenAITranslator::class,

    // Translator Services
    'translators' => [
        'openai' => EduLazaro\Laratext\Translators\OpenAITranslator::class,
        'claude' => EduLazaro\Laratext\Translators\ClaudeTranslator::class,
        'google' => EduLazaro\Laratext\Translators\GoogleTranslator::class,
    ],

    // OpenAI Configuration
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.4-nano'),
        'timeout' => 60,
        'retries' => 3,
    ],

    // Claude (Anthropic) Configuration
    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),
        'timeout' => 60,
        'retries' => 3,
        'max_tokens' => 4096,
    ],

    // Google Translator Configuration
    'google' => [
        'api_key' => env('GOOGLE_TRANSLATOR_API_KEY'),
        'timeout' => 20,
        'retries' => 3,
    ],

    // List the supported languages for translations.
    'languages' => [
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
    ],

    // Optional. Tells the translator what it is translating.
    'context' => '',
];
```

This configuration allows you to define your translation services, API keys, and the supported languages in your Laravel application.

### Giving the translator context

A translator sees short strings on their own, so it has to guess. "Book" can be a noun or a verb, and it has no way of knowing whether your application addresses people formally or that your product name must be left alone.

Fill in `context` and it is sent with every batch:

```php
'context' => 'A real estate CRM used by estate agents. Address the user formally. Never translate the product names Inmoqueen or Laratext.',
```

Use it for what the application does, the register you want, and the terms that must not be translated. It is optional: leave it empty and nothing is added to the prompt. Translators that take no prompt, such as Google Translate, ignore it.

Translation keys are used as context too. Since they travel to the translator with their text, a key like `nav.home` says that "Home" is a navigation link and should be "Inicio" rather than "Hogar". The prompt states that keys **may** be used this way, not that they must: a key like `common.name` says nothing useful, and one inherited from an older part of the codebase can be misleading, so the text itself always comes first.

This is an example of the `.env`:

```
OPENAI_API_KEY=your_openai_api_key
ANTHROPIC_API_KEY=your_anthropic_api_key
GOOGLE_TRANSLATOR_API_KEY=your_google_api_key
```

To use Claude as the translator for a scan run, pass `--translator=claude`:

```bash
php artisan laratext:scan --write --translator=claude
```

You can also set it as the project default by changing `default_translator` in `config/texts.php` or via the `default_translator` config entry. The Claude translator uses the [Messages API](https://docs.anthropic.com/en/api/messages) with prompt caching enabled on the system prompt, so repeated batches in a single scan reuse the cached instructions automatically.

## Usage

Here is how you can use the blade directive and the `text` function:

Use the `text()` helper function to fetch translations within your PHP code.

```php
text('key_name', 'default_value');
```

Use the `@text` Blade directive to fetch translations within your views.

```php
@text('key_name', 'default_value')
```

### Auto-Generated Text from Keys

You can also use just the key without providing a default value. The system will automatically generate readable text from the key name:

```php
// PHP usage with auto-generated text
text('hello_mate');        // Auto-generates: "Hello Mate"
text('welcome_back');      // Auto-generates: "Welcome Back"
text('user.first_name');   // Auto-generates: "First Name" (uses last part after dot)
```

```blade
{{-- Blade usage with auto-generated text --}}
@text('hello_mate')        {{-- Auto-generates: "Hello Mate" --}}
@text('welcome_back')      {{-- Auto-generates: "Welcome Back" --}}
@text('pages.contact_us')  {{-- Auto-generates: "Contact Us" --}}
```

The auto-generation works by:
- Taking the last part after dots (e.g., `pages.contact_us` → `contact_us`)
- Replacing underscores with spaces (e.g., `contact_us` → `contact us`)
- Capitalizing each word (e.g., `contact us` → `Contact Us`)

### Replacement Texts (Placeholders)

You can include placeholders in your text strings using the `:placeholder` syntax. These placeholders will be preserved during translation and can be replaced with actual values when displaying the text.

**Basic usage without replacements:**
```php
// PHP - displays text as-is with placeholders
echo text('welcome.user', 'Welcome, :name!');
// Output: "Welcome, :name!"
```

```blade
{{-- Blade - displays text as-is with placeholders --}}
@text('welcome.user', 'Welcome, :name!')
{{-- Output: "Welcome, :name!" --}}
```

**Usage with replacement values:**
```php
// PHP - replaces placeholders with actual values
echo text('welcome.user', 'Welcome, :name!', ['name' => 'John']);
// Output: "Welcome, John!" (or "¡Bienvenido, John!" in Spanish)

echo text('items.count', 'You have :count items.', ['count' => 5]);
// Output: "You have 5 items." (or "Tienes 5 artículos." in Spanish)
```

```blade
{{-- Blade - both syntaxes work identically --}}
{{ text('welcome.user', 'Welcome, :name!', ['name' => $user->name]) }}
@text('items.count', 'You have :count items in your cart.', ['count' => $cartItems])
@text('file.uploaded', ':count file uploaded.', ['count' => $fileCount])
```

When these texts are scanned and translated, the placeholders (`:name`, `:count`, etc.) will be preserved in all target languages.

### Plurals

Separate the forms with `|` and pass the quantity as `count`, which is the same syntax Laravel uses:

```php
text('items.count', 'One item|You have :count items', ['count' => $n]);
```

```blade
@text('items.count', 'One item|You have :count items', ['count' => $cartItems])
```

```
count = 1   ->  "One item"
count = 5   ->  "You have 5 items"
```

The form is chosen by Laravel's own selector, so the rules of each language apply. A language with three forms uses three, and the translated file drives the choice:

```json
// lang/ru.json
{ "items.count": "яблоко|яблока|яблок" }
```

Explicit ranges work as well, for when the wording changes at a threshold rather than at the singular:

```php
text('items.count', '{0} No items|[1,19] You have :count items|[20,*] You have plenty', ['count' => $n]);
```

Both signals are needed for this to kick in, a `|` in the text and a numeric `count` in the replacements. A text that merely contains a pipe is returned untouched, so `text('shortcut', 'Ctrl|Alt')` keeps being `Ctrl|Alt`.

Note that the translator decides how many forms each language gets. Review the result for languages with more forms than your source language, and lock the key once it is right:

```bash
php artisan laratext:lock items.count --lang=ru
```

## Scanning Translations

You can use the `laratext:scan` command to scan your project files for missing translation keys and translate them into multiple languages:

```php
php artisan laratext:scan --write
```

The scanner reads your source files statically, so the key must be a literal string. `text($key)` or `text("prefix.$name")` will work at runtime if the key already exists, but the scanner will never see it, so it is never created or translated.

You can also specify the target language or the translator to use:


```php
php artisan laratext:scan --write --lang=es --translator=openai
```

These are the command Options:

* `--write`: Write the missing keys to the language files.
* `--lang`: Target a specific language for translation (e.g., es for Spanish).
* `--dry` Perform a dry run (do not write).
* `--diff`: Show the diff of the changes made.
* `--resync`: Retranslate **every** key from scratch, ignoring existing translations (use after changing translator or model).
* `--only-missing`: Only translate brand-new keys; skip keys whose source text has drifted (they are listed as warnings instead).
* `--prune`: Remove keys present in lang files but no longer referenced in code.
* `--translator`: Specify the translator service to use (e.g., openai or google).

### Keeping Translations In Sync

By default, `laratext:scan --write` translates:

1. **New keys**: keys in code that don't exist yet in the lang files.
2. **Drifted keys**: keys whose source text in code no longer matches the value stored in `lang/{defaultLocale}.json`. These are retranslated in every target language so translations stay aligned with the source.

```bash
php artisan laratext:scan --write
# ℹ️  1 key(s) will be retranslated because their source text changed:
#    • pages.home.welcome
#        old: "Welcome"
#        new: "Welcome to our site"
# ... (translator called, JSONs updated)
```

#### Skipping drift: `--only-missing`

If you want the conservative behaviour (translate only new keys, leave drifted keys untouched), pass `--only-missing`. Drift is still detected and printed as a warning, but no API calls are made for drifted keys:

```bash
php artisan laratext:scan --write --only-missing
# ⚠️  1 key(s) have an updated source text but stale translations in es, fr:
#    • pages.home.welcome
#        old: "Welcome"
#        new: "Welcome to our site"
# Drop --only-missing to retranslate them, or edit the JSON files manually.
```

#### Protecting reviewed translations: locking keys

`--only-missing` is all or nothing: it stops every drifted key from being retranslated. When a translator reviews a single string and you want that one string protected forever, lock it:

```bash
php artisan laratext:lock save                 # in every configured language
php artisan laratext:lock save --lang=es       # only in Spanish
php artisan laratext:lock "errors.*"           # wildcards are allowed
php artisan laratext:lock --all --lang=es      # every key currently in es.json
```

A locked key is never written by the scanner. It is not retranslated when the source text drifts, it is not touched by `--resync`, and it is not removed by `--prune`. Locks are per language, so protecting a Spanish correction still lets French follow the English source.

Unlocking is symmetric, and `--all` asks for confirmation because the damage only shows up on the next scan:

```bash
php artisan laratext:unlock save --lang=es
php artisan laratext:unlock --all --lang=es
php artisan laratext:unlock --all --force      # no question, for scripts
```

Locks live in `lang/.locked/{locale}.json` as a plain list of keys, so they are easy to review in a diff. Commit them: they are a decision about your translations, not local state. If nothing is locked the files do not exist and the scanner behaves exactly as it did before.

When a scan skips something, it says so:

```
🔒 es: 1 key(s) kept as they are, they are locked.
🔒 1 key(s) not sent to the translator, they are locked in every language.
```

That second line is also a saving: a key locked everywhere never reaches the API.

#### Forcing a full retranslation: `--resync`

`--resync` retranslates **every** key in your codebase from scratch, even keys whose source text has not changed. Useful when you've switched translator providers, upgraded the OpenAI model, or want to regenerate inconsistent translations left over from older runs. Expect this to be expensive in tokens.

```bash
php artisan laratext:scan --write --resync
```

#### Cleaning up orphan keys: `--prune`

`--prune` detects the opposite drift: keys that still live in `lang/{locale}.json` but are no longer referenced anywhere in code (removed `text()` / `@text` calls). By default it only lists them; combined with `--write` it removes them from every configured language file:

```bash
php artisan laratext:scan --prune              # list orphan keys only
php artisan laratext:scan --write --prune      # actually delete orphan keys
```

#### Recommended cadence

* **During development**: run `php artisan laratext:scan --write` after adding or editing `@text` / `text()` calls. New keys get translated; edited source texts get retranslated automatically.
* **Periodically (weekly / pre-release / CI)**: run `php artisan laratext:scan --write --prune` to also drop orphan keys left behind by refactors.
* **After switching model or translator**: run `php artisan laratext:scan --write --resync` once to regenerate every translation against the new backend.


## Creating translators

To create a custom translator, you need to implement the `TranslatorInterface`. This will define the structure and method that will handle the translation.

To facilitate the creation of custom translators, you can create a `make:translator` command that will generate the required files for a new translator class.

To create a translator run:

```bash
php artisan make:translator BeautifulTranslator
```

This will create the `BeautifulTranslator.php` file in the `app/Translators` directory: 

```php
namespace App\Translators;

use EduLazaro\Laratext\Contracts\TranslatorInterface;

class BeautifulTranslator implements TranslatorInterface
{
    public function translate(string $text, string $from, array $to): array
    {
        // TODO: Implement your translation logic here.

        $results = [];

        foreach ($to as $language) {
            $results[$language] = $text; // Dummy return same text
        }

        return $results;
    }
}
```

The `translate` method, which translates a single string into one or more target languages, is required:

```
translate(string $text, string $from, array $to): array
```

Optionally, you can implement the `translateMany` method to translate multiple texts in batch, which can improve performance when supported by the translation API:

```
translateMany(array $texts, string $from, array $to): array
```

If `translateMany` is not implemented, only single-string translations (translate) will be available for batch processing. For full support, both methods are recommended, so there are less requests and create a cost effective solution.

## Laratext in the wild

Read how Kenodo uses Laratext for AI-powered Laravel i18n: [English](https://kenodo.com/blog/laratext-laravel-i18n-ai) | [Español](https://kenodo.com/es/blog/laratext-laravel-i18n-ai).

## License

Larakeep is open-sourced software licensed under the [MIT license](LICENSE.md).
