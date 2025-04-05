<?php

use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class ScanTranslationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the lang path exists
        File::ensureDirectoryExists(lang_path());

        // Cleanup old test files
        File::delete(lang_path('en.json'));
        File::delete(lang_path('es.json'));

        // Create a dummy blade file to scan
        File::put(resource_path('views/test.blade.php'), "@text('pages.home.welcome', 'Welcome')");
    }

    /** @test */
    public function it_scans_and_writes_translations_to_file()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'en' => 'Welcome',
                                'es' => 'Bienvenido',
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('laratext:scan --write --translator=openai')
            ->expectsOutput('Scanning project for translation keys...')
            ->expectsOutput('Found 1 unique keys.')
            ->expectsOutput('Processing language: en')
            ->expectsOutput('Translation file updated: ' . lang_path('en.json'))
            ->expectsOutput('Processing language: es')
            ->expectsOutput('Translation file updated: ' . lang_path('es.json'))
            ->expectsOutput('All languages processed.')
            ->assertExitCode(0);

        $this->assertFileExists(lang_path('en.json'));
        $this->assertFileExists(lang_path('es.json'));

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        $this->assertEquals('Welcome', $enContent['pages.home.welcome']);
        $this->assertEquals('Bienvenido', $esContent['pages.home.welcome']);
    }

    protected function tearDown(): void
    {
        // Clean up
        File::delete(resource_path('views/test.blade.php'));
        File::delete(lang_path('en.json'));
        File::delete(lang_path('es.json'));

        parent::tearDown();
    }
}
