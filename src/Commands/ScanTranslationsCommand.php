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
            foreach ($languages as $lang) {
                if (!array_key_exists($key, $existingTranslations[$lang])) {
                    $missingTexts[$key] = $value;
                    break;
                }
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
                if (!array_key_exists($key, $current)) {
                    $current[$key] = $langs[$lang] ?? $key;
                }
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
        if (! $option) {
            return null;
        }

        $configMap = config('texts.translators', []);
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

            preg_match_all(
                "/Text::get\(\s*(['\"])(.*?)\\1\s*,\s*(['\"])((?:\\\\.|(?!\\3).)*?)\\3/s",
                $content,
                $matches1
            );

            preg_match_all(
                "/@text\(\s*(['\"])(.*?)\\1\s*,\s*(['\"])((?:\\\\.|(?!\\3).)*?)\\3/s",
                $content,
                $matches2
            );

            preg_match_all(
                "/(?<!->)\btext\(\s*(['\"])(.*?)\\1\s*,\s*(['\"])((?:\\\\.|(?!\\3).)*?)\\3/s",
                $content,
                $matches3
            );

            foreach ([$matches1, $matches2, $matches3] as $match) {
                foreach ($match[2] as $i => $key) {
                    $value = stripcslashes($match[4][$i] ?? $key);
                    $keyValuePairs[$key] = $value;
                }
            }
        }

        return $keyValuePairs;
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
