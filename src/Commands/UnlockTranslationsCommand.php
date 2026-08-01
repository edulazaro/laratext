<?php

namespace EduLazaro\Laratext\Commands;

use EduLazaro\Laratext\TranslationLocks;
use Illuminate\Console\Command;

/**
 * Unlock translation keys so the scanner can write them again.
 */
class UnlockTranslationsCommand extends Command
{
    protected $signature = 'laratext:unlock
                            {key? : The key to unlock, wildcards allowed (errors.*)}
                            {--all : Unlock every locked key}
                            {--lang= : Target a single language instead of all of them}
                            {--force : Skip the confirmation of --all}';

    protected $description = 'Unlock translation keys so laratext:scan can overwrite them again.';

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

        if ($this->option('all') && ! $this->confirmUnlockAll($languages)) {
            $this->info('Nothing was unlocked.');

            return self::SUCCESS;
        }

        foreach ($languages as $lang) {
            $keys = $this->option('all')
                ? TranslationLocks::all($lang)
                : [$key];

            $removed = TranslationLocks::unlock($lang, $keys);

            $this->info(sprintf('%s: %d key(s) unlocked.', $lang, count($removed)));
        }

        return self::SUCCESS;
    }

    /**
     * Ask before unlocking everything, since the damage only shows up on the
     * next scan, when the translations are overwritten.
     *
     * @param array<int, string> $languages
     * @return bool
     */
    protected function confirmUnlockAll(array $languages): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $total = 0;

        foreach ($languages as $lang) {
            $total += count(TranslationLocks::all($lang));
        }

        if ($total === 0) {
            return true;
        }

        return $this->confirm(
            sprintf(
                'This unlocks %d key(s) in %s. The next scan may overwrite them. Continue?',
                $total,
                implode(', ', $languages)
            ),
            false
        );
    }
}
