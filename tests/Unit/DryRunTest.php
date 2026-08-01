<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class DryRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(lang_path());

        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }

        File::put(resource_path('views/test.blade.php'), "@text('save', 'Save')");

        Http::fake();
    }

    protected function tearDown(): void
    {
        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }

        parent::tearDown();
    }

    protected function writeEverywhere(array $translations): void
    {
        foreach (['en', 'es', 'fr'] as $lang) {
            File::put(lang_path("{$lang}.json"), json_encode($translations));
        }
    }

    public function test_it_never_calls_the_translator()
    {
        $this->artisan('laratext:scan --dry')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_never_writes()
    {
        $this->artisan('laratext:scan --dry')->assertExitCode(0);

        $this->assertFileDoesNotExist(lang_path('es.json'));
    }

    public function test_it_reports_nothing_to_do_when_everything_is_translated()
    {
        $this->writeEverywhere(['save' => 'Save']);

        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain('0 new key(s)')
            ->expectsOutputToContain('Nothing would be sent to the translator.')
            ->assertExitCode(0);
    }

    public function test_it_counts_a_brand_new_key()
    {
        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain('1 new key(s)')
            ->expectsOutputToContain('1 key(s) would be sent to the translator.')
            ->assertExitCode(0);
    }

    public function test_it_separates_drift_from_new_keys()
    {
        // Same key, different source text: this is drift, not a new key.
        $this->writeEverywhere(['save' => 'Store']);

        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain('0 new key(s)')
            ->expectsOutputToContain('1 key(s) with a changed source text, 1 of them would be retranslated')
            ->assertExitCode(0);
    }

    public function test_it_honours_only_missing()
    {
        $this->writeEverywhere(['save' => 'Store']);

        $this->artisan('laratext:scan --dry --only-missing')
            ->expectsOutputToContain('1 key(s) with a changed source text, 0 of them would be retranslated')
            ->expectsOutputToContain('Nothing would be sent to the translator.')
            ->assertExitCode(0);
    }

    public function test_it_reports_orphans()
    {
        $this->writeEverywhere(['save' => 'Save', 'gone.from.code' => 'Gone']);

        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain('1 orphan key(s) in the language files')
            ->assertExitCode(0);
    }
}
