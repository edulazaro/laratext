<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class SourceLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(lang_path());

        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }

        // The application runs in Spanish while its source texts are English.
        config(['app.locale' => 'es']);

        File::put(resource_path('views/test.blade.php'), "@text('save', 'Save')");

        File::put(lang_path('en.json'), json_encode(['save' => 'Save']));
        File::put(lang_path('es.json'), json_encode(['save' => 'Guardar']));
        File::put(lang_path('fr.json'), json_encode(['save' => 'Enregistrer']));

        Http::fake();
    }

    protected function tearDown(): void
    {
        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }

        parent::tearDown();
    }

    public function test_without_the_option_the_application_locale_is_used()
    {
        // "Guardar" against "Save": the key looks drifted, which is the old
        // behaviour and must not change for projects that never set this.
        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain('1 key(s) with a changed source text')
            ->assertExitCode(0);
    }

    public function test_the_source_locale_is_compared_instead()
    {
        config(['texts.source_locale' => 'en']);

        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain('0 key(s) with a changed source text')
            ->expectsOutputToContain('Nothing would be sent to the translator.')
            ->assertExitCode(0);
    }

    public function test_a_real_change_is_still_detected()
    {
        config(['texts.source_locale' => 'en']);

        File::put(resource_path('views/test.blade.php'), "@text('save', 'Save changes')");

        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain('1 key(s) with a changed source text')
            ->assertExitCode(0);
    }

    public function test_an_empty_value_falls_back_to_the_application_locale()
    {
        config(['texts.source_locale' => '']);

        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain('1 key(s) with a changed source text')
            ->assertExitCode(0);
    }
}
