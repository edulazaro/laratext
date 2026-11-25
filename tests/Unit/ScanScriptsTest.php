<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class ScanScriptsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(lang_path());
        File::ensureDirectoryExists(resource_path('js/Pages'));

        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }

        File::put(resource_path('js/Pages/Home.jsx'), <<<'JSX'
            export default function Home() {
              const text = useText();
              return <p title={text('home.title', "L'Andorra")}>{text(label, 'Unread')}</p>;
            }
            JSX);
        File::put(resource_path('js/Pages/Home.d.ts'), "export declare function text(key: string): string;\ntext('types.only', 'Skipped');");

        Http::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('js'));

        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }

        parent::tearDown();
    }

    /** @test */
    public function scripts_are_not_scanned_unless_configured()
    {
        config(['texts.scan.scripts.paths' => []]);

        $this->artisan('laratext:scan --dry')
            ->doesntExpectOutputToContain('home.title')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_scans_the_configured_folders_and_reports_unreadable_calls()
    {
        config(['texts.scan.scripts.paths' => ['resources/js']]);

        $this->artisan('laratext:scan --dry')
            ->expectsOutputToContain("+ home.title: L'Andorra")
            ->expectsOutputToContain('1 call(s) in scripts could not be read')
            ->expectsOutputToContain('Pages/Home.jsx:3')
            ->doesntExpectOutputToContain('types.only')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    /** @test */
    public function it_writes_script_keys_like_any_other()
    {
        config(['texts.scan.scripts.paths' => [resource_path('js')]]);

        $this->artisan('laratext:scan --write --lang=en')->assertExitCode(0);

        $written = json_decode(File::get(lang_path('en.json')), true);

        $this->assertSame("L'Andorra", $written['home.title']);
        $this->assertArrayNotHasKey('types.only', $written);
    }
}
