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
        $keys = $this->extractKeysFromFiles($files);

        if (empty($keys)) {
            $this->info('No translation keys found.');
            return;
        }

        $this->info('Found ' . count($keys) . ' unique keys.');

        if ($this->option('dry')) {
            return $this->handleDryRun($keys);
        }

        $translator = $this->resolveTranslator($this->option('translator'));

        $languages = $this->option('lang')
            ? [$this->option('lang')]
            : array_keys(config('texts.languages'));

        foreach ($languages as $language) {
            $this->processLanguage($language, $keys, $translator);
        }

        $this->info('All languages processed.');
    }

    /**
     * Resolve the translator class based on option or config.
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
     * Process a single language: find new keys and update the language file.
     *
     * @param  string  $language
     * @param  array  $keys
     * @param  object|null  $translator
     * @return void
     */
    protected function processLanguage(string $language, array $keys, $translator = null): void
    {
        $this->info("Processing language: {$language}");

        $path = lang_path("{$language}.json");
        $existingTranslations = file_exists($path)
            ? json_decode(file_get_contents($path), true)
            : [];

        $newKeys = array_diff($keys, array_keys($existingTranslations));

        if (empty($newKeys)) {
            $this->info("No new keys to add for {$language}.");
            return;
        }

        $updatedTranslations = $this->fillTranslations($newKeys, $existingTranslations, $language, $translator);

        if ($this->option('diff')) {
            $this->info("Showing diff for {$language}:");
            foreach ($newKeys as $key) {
                $this->line("+ \"$key\": \"{$updatedTranslations[$key]}\"");
            }
        }

        if ($this->option('write')) {
            file_put_contents(
                $path,
                json_encode($updatedTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $this->info("Translation file updated: {$path}");
        } else {
            $this->info("Run with --write to save changes for {$language}.");
        }
    }

    /**
     * Fill translations for the given keys and language.
     *
     * @param  array  $newKeys
     * @param  array  $translations
     * @param  string  $language
     * @param  object|null  $translator
     * @return array
     */
    protected function fillTranslations(array $newKeys, array $translations, string $language, $translator = null): array
    {
        $defaultLanguage = config('app.locale');

        foreach ($newKeys as $key) {
            if ($translator) {
                $results = $translator->translate($key, $defaultLanguage, [$language]);
                $translations[$key] = $results[$language] ?? $key;
            } else {
                $translations[$key] = $key;
            }
        }

        return $translations;
    }

    /**
     * Extract translation keys from all project files.
     *
     * @param  iterable  $files
     * @return array
     */
    protected function extractKeysFromFiles(iterable $files): array
    {
        $keys = [];

        foreach ($files as $file) {
            $content = file_get_contents($file->getRealPath());

            preg_match_all("/Text::get\\(['\"](.*?)['\"]/", $content, $matches1);
            preg_match_all("/@text\\(['\"](.*?)['\"]/", $content, $matches2);
            preg_match_all("/text\\(['\"](.*?)['\"]/", $content, $matches3);

            $keys = array_merge($keys, $matches1[1], $matches2[1], $matches3[1]);
        }

        return array_unique($keys);
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
            ->exclude(['vendor', 'node_modules', 'storage', 'bootstrap/cache'])
            ->name('*.php')
            ->name('*.blade.php');
    }

    /**
     * Handle a dry run by listing keys that would be added.
     *
     * @param  array  $newKeys
     * @return void
     */
    protected function handleDryRun(array $newKeys): void
    {
        $this->info('Dry run: these keys would be added:');

        foreach ($newKeys as $key) {
            $this->line("- $key");
        }
    }
}
