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
        'google' => EduLazaro\Laratext\Translators\GoogleTranslator::class,
    ],

    // OpenAI Configuration
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
        'timeout' => 10,
        'retries' => 3,
    ],

    // Google Translator Configuration
    'google' => [
        'api_key' => env('GOOGLE_TRANSLATOR_API_KEY'),
        'timeout' => 10,
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
GOOGLE_TRANSLATOR_API_KEY=your_google_api_key
```

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

## Scanning Translations

You can use the `laratext:scan` command to scan your project files for missing translation keys and optionally translate them into multiple languages.

```php
php artisan laratext:scan --write --lang=es --translator=google
```

These are the command Options:

* `--write`: Write the missing keys to the language files.
* `--lang`: Target a specific language for translation (e.g., es for Spanish).
* `--dry` Perform a dry run (do not write).
* `--diff`: Show the diff of the changes made.
* `--translator`: Specify the translator service to use (e.g., openai or google).


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

## License

Larakeep is open-sourced software licensed under the [MIT license](LICENSE.md).
