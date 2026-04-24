# Laratext for Laravel

<p align="center">
    <a href="https://packagist.org/packages/edulazaro/laratext"><img src="https://img.shields.io/packagist/dt/edulazaro/laratext" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/laratext"><img src="https://img.shields.io/packagist/v/edulazaro/laratext" alt="Latest Stable Version"></a>
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
];
```

This configuration allows you to define your translation services, API keys, and the supported languages in your Laravel application.

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

## Scanning Translations

You can use the `laratext:scan` command to scan your project files for missing translation keys and translate them into multiple languages:

```php
php artisan laratext:scan --write
```

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

## License

Larakeep is open-sourced software licensed under the [MIT license](LICENSE.md).
