<?php

namespace EduLazaro\Laratext\Commands;

use EduLazaro\Laratext\TranslationLocks;
use Illuminate\Console\Command;

/**
 * Lock translation keys so the scanner never writes them again.
 */
class LockTranslationsCommand extends Command
{
    protected $signature = 'laratext:lock
                            {key? : The key to lock, wildcards allowed (errors.*)}
                            {--all : Lock every key currently present in the language file}
                            {--lang= : Target a single language instead of all of them}';

    protected $description = 'Lock translation keys so laratext:scan never overwrites or prunes them.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $key = $this->argument('key');

        if (! $key && ! $this->option('all')) {
            $this->error('Specify a key or use --all.');

            return self::FAILURE;
        }

        if ($key && $this->option('all')) {
            $this->error('Use either a key or --all, not both.');

            return self::FAILURE;
        }

        $languages = $this->option('lang')
            ? [$this->option('lang')]
            : array_keys(config('texts.languages', []));

        if (empty($languages)) {
            $this->error('No languages configured in texts.languages.');

            return self::FAILURE;
        }

        foreach ($languages as $lang) {
            $keys = $this->option('all')
                ? $this->keysOf($lang)
                : [$key];

            if (empty($keys)) {
                $this->line("{$lang}: nothing to lock.");
                continue;
            }

            $added = TranslationLocks::lock($lang, $keys);

            $this->info(sprintf(
                '%s: %d key(s) locked, %d already were.',
                $lang,
                count($added),
                count($keys) - count($added)
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Keys currently present in the language file.
     *
     * @param string $lang
     * @return array<int, string>
     */
    protected function keysOf(string $lang): array
    {
        $path = lang_path("{$lang}.json");

        if (! file_exists($path)) {
            return [];
        }

        $translations = json_decode(file_get_contents($path), true);

        return is_array($translations) ? array_keys($translations) : [];
    }
}
