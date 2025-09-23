<?php

namespace EduLazaro\Laratext\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;


/**
 * Scan project files and auto translate strings
 */
class ScanTranslationsCommand extends Command
{
    protected $signature = 'laratext:scan
                            {--write : Write missing keys to lang files}
                            {--lang= : Target specific language}
                            {--dry : Dry run, do not write}
                            {--diff : Show diff of changes}
                            {--resync : Retranslate texts when source language values have changed}
                            {--translator= : Translator service to use (optional)}';

    protected $description = 'Scan project files and update translation files with missing keys.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->info('Scanning project for translation keys...');

        $files = $this->getProjectFiles();
        $texts = $this->extractTextsFromFiles($files);

        if (empty($texts)) {
            $this->info('No translation keys found.');
            return;
        }

        $this->info('Found ' . count($texts) . ' unique keys.');

        if ($this->option('dry')) {
            return $this->handleDryRun($texts);
        }

        $translator = $this->resolveTranslator($this->option('translator'));

        $languages = $this->option('lang')
            ? [$this->option('lang')]
            : array_keys(config('texts.languages'));

        $defaultLanguage = config('app.locale');

        // Load existing translations per language
        $existingTranslations = [];
        foreach ($languages as $lang) {
            $path = lang_path("{$lang}.json");
            $existingTranslations[$lang] = file_exists($path)
                ? json_decode(file_get_contents($path), true)
                : [];
        }

        // Determine missing keys per language
        $missingTexts = [];
        foreach ($texts as $key => $value) {
            // Check if key is missing in any language
            foreach ($languages as $lang) {
                if (!array_key_exists($key, $existingTranslations[$lang] ?? [])) {
                    $missingTexts[$key] = $value;
                    continue 2;
                }
            }

            // Check if source language value has changed (only when --resync is used)
            if ($this->option('resync') &&
                array_key_exists($key, $existingTranslations[$defaultLanguage] ?? []) &&
                ($existingTranslations[$defaultLanguage][$key] ?? '') !== $value) {
                $missingTexts[$key] = $value;
            }
        }

        if (empty($missingTexts)) {
            $this->info("No new keys to translate.");
            return;
        }

        $translations = [];

        if ($translator && method_exists($translator, 'batchTranslate')) {
            $translations = $translator->batchTranslate($missingTexts, $defaultLanguage, $languages);
        } elseif ($translator && method_exists($translator, 'translateMany')) {
            $translations = $translator->translateMany($missingTexts, $defaultLanguage, $languages);
        } elseif ($translator) {
            foreach ($missingTexts as $key => $value) {
                $results = $translator->translate($value, $defaultLanguage, $languages);
                $translations[$key] = $results;
            }
        } else {
            foreach ($missingTexts as $key => $value) {
                $translations[$key] = [];
                foreach ($languages as $lang) {
                    $translations[$key][$lang] = $value;
                }
            }
        }

        foreach ($languages as $lang) {
            $path = lang_path("{$lang}.json");
            $current = $existingTranslations[$lang] ?? [];

            foreach ($translations as $key => $langs) {
                $current[$key] = $langs[$lang] ?? $key;
            }

            if ($this->option('diff')) {
                $this->info("Diff for {$lang}:");
                foreach ($translations as $key => $langs) {
                    if (isset($langs[$lang])) {
                        $this->line("+ \"$key\": \"{$langs[$lang]}\"");
                    }
                }
            }

            if ($this->option('write')) {
                $directory = dirname($path);
                is_dir($directory) || mkdir($directory, 0755, true);

                file_put_contents(
                    $path,
                    json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );

                $this->info("Translation file updated: {$path}");
            } else {
                $this->info("Run with --write to save changes for {$lang}.");
            }
        }

        $this->info('All translations processed.');
    }

    /**
     * Resolve the translator class from the command option or config.
     *
     * @param  string|null  $option
     * @return object|null
     */
    protected function resolveTranslator(?string $option): ?object
    {
        $configMap = config('texts.translators', []);

        if (! $option) {
            // Use default_translator from config
            $defaultTranslator = config('texts.default_translator');
            if ($defaultTranslator) {
                $translatorClass = $configMap[$defaultTranslator] ?? $defaultTranslator;
                return app($translatorClass);
            }

            // If no default, get first available translator
            if (! empty($configMap)) {
                $firstTranslator = array_values($configMap)[0];
                return app($firstTranslator);
            }

            return null;
        }

        $translatorClass = $configMap[$option] ?? $option;

        return app($translatorClass);
    }

    /**
     * Extract translation keys from project files.
     *
     * @param  iterable  $files
     * @return array<string, string>  Key-value pairs of translatable strings.
     */
    protected function extractTextsFromFiles(iterable $files): array
    {
        $keyValuePairs = [];

        foreach ($files as $file) {
            $content = file_get_contents($file->getRealPath());

            // First, extract two-parameter calls: text('key', 'value')
            $patterns = [
                "/Text::get\(\s*(['\"])(.*?)\\1\s*,\s*(['\"])((?:\\\\.|(?!\\3).)*?)\\3/s",
                "/@text\(\s*(['\"])(.*?)\\1\s*,\s*(['\"])((?:\\\\.|(?!\\3).)*?)\\3/s",
                "/(?<!->)\btext\(\s*(['\"])(.*?)\\1\s*,\s*(['\"])((?:\\\\.|(?!\\3).)*?)\\3/s"
            ];

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $content, $matches);
                foreach ($matches[2] as $i => $key) {
                    $value = stripcslashes($matches[4][$i] ?? $key);
                    $keyValuePairs[$key] = $value;
                }
            }

            // Then, extract single-parameter calls: text('key') - only if not already extracted
            $singlePatterns = [
                "/Text::get\(\s*(['\"])([^'\"\\n\\r]*?)\\1\s*\);/",
                "/@text\(\s*(['\"])([^'\"\\n\\r]*?)\\1\s*\)/",
                "/(?<!->)\btext\(\s*(['\"])([^'\"\\n\\r]*?)\\1\s*\);/"
            ];

            foreach ($singlePatterns as $pattern) {
                preg_match_all($pattern, $content, $matches);
                foreach ($matches[2] as $key) {
                    if (!isset($keyValuePairs[$key])) {
                        $keyValuePairs[$key] = $this->keyToText($key);
                    }
                }
            }
        }

        return $keyValuePairs;
    }

    /**
     * Transform a key into readable text.
     *
     * @param  string  $key
     * @return string
     */
    protected function keyToText(string $key): string
    {
        // Remove common prefixes and get the last part if dot-separated
        $parts = explode('.', $key);
        $lastPart = end($parts);

        // Replace underscores with spaces and capitalize first letter of each word
        $text = str_replace('_', ' ', $lastPart);
        return ucwords($text);
    }

    /**
     * Get all PHP and Blade files in the project.
     *
     * @return Finder
     */
    protected function getProjectFiles(): Finder
    {
        return (new Finder())
            ->in(base_path())
            ->exclude(['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'tests'])
            ->name('*.php')
            ->name('*.blade.php');
    }

    /**
     * Handle a dry run by listing keys that would be added.
     *
     * @param  array  $newKeys
     * @return void
     */
    protected function handleDryRun(array $texts): void
    {
        $this->info('Dry run: these keys would be added:');
        foreach ($texts as $key => $value) {
            $this->line("- $key: $value");
        }
    }
}
