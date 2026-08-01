<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\TranslationLocks;
use EduLazaro\Laratext\Tests\TestCase;

class TranslationLocksTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['en', 'es', 'fr'] as $lang) {
            $path = TranslationLocks::path($lang);

            if (file_exists($path)) {
                unlink($path);
            }

            $langFile = lang_path("{$lang}.json");

            if (file_exists($langFile)) {
                unlink($langFile);
            }
        }

        parent::tearDown();
    }

    protected function writeLangFile(string $lang, array $translations): void
    {
        $path = lang_path("{$lang}.json");

        is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);

        file_put_contents($path, json_encode($translations));
    }

    public function test_nothing_is_locked_by_default()
    {
        $this->assertSame([], TranslationLocks::all('es'));
        $this->assertFalse(TranslationLocks::isLocked('es', 'save'));
        $this->assertFileDoesNotExist(TranslationLocks::path('es'));
    }

    public function test_it_locks_and_unlocks_a_key()
    {
        TranslationLocks::lock('es', ['save']);

        $this->assertTrue(TranslationLocks::isLocked('es', 'save'));
        $this->assertFalse(TranslationLocks::isLocked('es', 'cancel'));

        TranslationLocks::unlock('es', ['save']);

        $this->assertFalse(TranslationLocks::isLocked('es', 'save'));
    }

    public function test_locks_are_per_language()
    {
        TranslationLocks::lock('es', ['save']);

        $this->assertTrue(TranslationLocks::isLocked('es', 'save'));
        $this->assertFalse(TranslationLocks::isLocked('fr', 'save'));
    }

    public function test_it_supports_wildcards()
    {
        TranslationLocks::lock('es', ['errors.*']);

        $this->assertTrue(TranslationLocks::isLocked('es', 'errors.not_found'));
        $this->assertTrue(TranslationLocks::isLocked('es', 'errors.forbidden'));
        $this->assertFalse(TranslationLocks::isLocked('es', 'pages.home'));
    }

    public function test_the_file_disappears_when_nothing_is_left()
    {
        TranslationLocks::lock('es', ['save']);
        $this->assertFileExists(TranslationLocks::path('es'));

        TranslationLocks::unlock('es', ['save']);
        $this->assertFileDoesNotExist(TranslationLocks::path('es'));
    }

    public function test_locking_twice_is_harmless()
    {
        $this->assertSame(['save'], TranslationLocks::lock('es', ['save']));
        $this->assertSame([], TranslationLocks::lock('es', ['save']));
        $this->assertSame(['save'], TranslationLocks::all('es'));
    }

    public function test_the_lock_command_needs_a_key_or_all()
    {
        $this->artisan('laratext:lock')
            ->expectsOutputToContain('Specify a key or use --all.')
            ->assertExitCode(1);
    }

    public function test_the_lock_command_locks_a_single_key_in_every_language()
    {
        $this->artisan('laratext:lock', ['key' => 'save'])->assertExitCode(0);

        $this->assertTrue(TranslationLocks::isLocked('es', 'save'));
        $this->assertTrue(TranslationLocks::isLocked('fr', 'save'));
    }

    public function test_the_lock_command_targets_one_language()
    {
        $this->artisan('laratext:lock', ['key' => 'save', '--lang' => 'es'])->assertExitCode(0);

        $this->assertTrue(TranslationLocks::isLocked('es', 'save'));
        $this->assertFalse(TranslationLocks::isLocked('fr', 'save'));
    }

    public function test_the_lock_command_with_all_locks_the_current_keys()
    {
        $this->writeLangFile('es', ['save' => 'Guardar', 'cancel' => 'Cancelar']);

        $this->artisan('laratext:lock', ['--all' => true, '--lang' => 'es'])->assertExitCode(0);

        $this->assertTrue(TranslationLocks::isLocked('es', 'save'));
        $this->assertTrue(TranslationLocks::isLocked('es', 'cancel'));
        $this->assertFalse(TranslationLocks::isLocked('es', 'added_later'));
    }

    public function test_the_unlock_command_needs_a_key_or_all()
    {
        $this->artisan('laratext:unlock')
            ->expectsOutputToContain('Specify a key or use --all.')
            ->assertExitCode(1);
    }

    public function test_the_unlock_command_removes_a_key()
    {
        TranslationLocks::lock('es', ['save']);

        $this->artisan('laratext:unlock', ['key' => 'save', '--lang' => 'es'])->assertExitCode(0);

        $this->assertFalse(TranslationLocks::isLocked('es', 'save'));
    }

    public function test_unlock_all_asks_for_confirmation()
    {
        TranslationLocks::lock('es', ['save']);

        $this->artisan('laratext:unlock', ['--all' => true, '--lang' => 'es'])
            ->expectsConfirmation(
                'This unlocks 1 key(s) in es. The next scan may overwrite them. Continue?',
                'no'
            )
            ->assertExitCode(0);

        $this->assertTrue(TranslationLocks::isLocked('es', 'save'));
    }

    public function test_unlock_all_skips_the_question_with_force()
    {
        TranslationLocks::lock('es', ['save', 'cancel']);

        $this->artisan('laratext:unlock', ['--all' => true, '--lang' => 'es', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame([], TranslationLocks::all('es'));
    }
}
