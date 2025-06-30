<?php

use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Commands\ScanTranslationsCommand;
use Symfony\Component\Finder\Finder;

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
                                'pages.home.welcome' => [
                                    'en' => 'Welcome',
                                    'es' => 'Bienvenido',
                                ]
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('laratext:scan --write --translator=openai')
            ->expectsOutput('Scanning project for translation keys...')
            ->expectsOutput('Found 1 unique keys.')
            ->expectsOutput('Translation file updated: ' . lang_path('en.json'))
            ->expectsOutput('Translation file updated: ' . lang_path('es.json'))
            ->expectsOutput('All translations processed.')
            ->assertExitCode(0);

        $this->assertFileExists(lang_path('en.json'));
        $this->assertFileExists(lang_path('es.json'));

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        $this->assertEquals('Welcome', $enContent['pages.home.welcome']);
        $this->assertEquals('Bienvenido', $esContent['pages.home.welcome']);
    }

    /** @test */
    public function it_extracts_texts_from_php_fixture_file_correctly()
    {
        $filePath = __DIR__ . '/../Fixtures/test.php';
        $command = app(\EduLazaro\Laratext\Commands\ScanTranslationsCommand::class);

        $finder = (new \Symfony\Component\Finder\Finder())
            ->files()
            ->name('test.php')
            ->in(dirname($filePath));

        $result = (new \ReflectionClass($command))
            ->getMethod('extractTextsFromFiles')
            ->invoke($command, $finder);

        $expected = [
            'key.simple.php' => 'Simple PHP value',
            'key.single.inside.php' => "PHP with 'single' quotes inside",
            'key.double.inside.php' => 'PHP with "double" quotes inside',
            'key.escaped.single.php' => "PHP with escaped 'single' quotes",
            'key.helper' => 'Helper function call',
            'key.nospace' => 'No space in call',
            'key.welcome_user' => 'Welcome, :name!',
            'key.items_in_cart' => 'You have :count items in your cart, :name.',
            'key.file_uploaded' => ':count file uploaded.',
            'key.files_uploaded' => ':count files uploaded.',
            'key.hello_user' => 'Hello, :name!',
            'key.order_status' => 'Your order #:order_id is :status.',
            'key.placeholder_escaped' => "This is a placeholder: ':name' that should not replace.",
        ];

        $this->assertEqualsCanonicalizing($expected, $result);
    }

    /** @test */
    public function it_extracts_texts_from_blade_fixture_file_correctly()
    {
        $filePath = __DIR__ . '/../Fixtures/test.blade.php';
        $command = app(\EduLazaro\Laratext\Commands\ScanTranslationsCommand::class);

        $finder = (new \Symfony\Component\Finder\Finder())
            ->files()
            ->name('test.blade.php')
            ->in(dirname($filePath));

        $result = (new \ReflectionClass($command))
            ->getMethod('extractTextsFromFiles')
            ->invoke($command, $finder);

        $expected = [
            // From @text() calls
            'key.simple' => 'Simple value',
            'key.single.inside' => "Value with 'single' quotes inside",
            'key.double.inside' => 'Value with "double" quotes inside',
            'key.escaped.double' => 'Value with escaped "double" quotes',

            // Newly added placeholders
            'key.blade.welcome_user' => 'Welcome, :name!',
            'key.blade.items_in_cart' => 'You have :count items in your cart, :name.',
            'key.blade.file_uploaded' => ':count file uploaded.',
            'key.blade.files_uploaded' => ':count files uploaded.',
            'key.blade.order_status' => 'Your order #:order_id is :status.',
            'key.blade.placeholder_escaped' => "This is a placeholder: ':name' that should not replace.",

            // From Text::get() calls
            'key.simple.php' => 'Simple PHP value',
            'key.single.inside.php' => "PHP with 'single' quotes inside",
            'key.double.inside.php' => 'PHP with "double" quotes inside',
            'key.escaped.single.php' => "PHP with escaped 'single' quotes",

            // From text() helper calls
            'key.helper' => 'Helper function call',
            'key.nospace' => 'No space in call',
        ];

        $this->assertEqualsCanonicalizing($expected, $result);
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
