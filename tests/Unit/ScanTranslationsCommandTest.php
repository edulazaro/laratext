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

    /** @test */
    public function it_retranslates_when_source_value_changes()
    {
        // First, create existing translation files with initial translations
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.about.title' => 'About Us'
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.about.title' => 'Acerca de nosotros'
        ]));

        // Update the blade file with one changed value and one unchanged
        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome to our site')\n" .
            "@text('pages.about.title', 'About Us')"
        );

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'pages.home.welcome' => [
                                    'en' => 'Welcome to our site',
                                    'es' => 'Bienvenido a nuestro sitio',
                                ]
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('laratext:scan --write --resync --translator=openai')
            ->expectsOutput('Scanning project for translation keys...')
            ->expectsOutput('Found 2 unique keys.')
            ->expectsOutput('Translation file updated: ' . lang_path('en.json'))
            ->expectsOutput('Translation file updated: ' . lang_path('es.json'))
            ->expectsOutput('All translations processed.')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        // Verify only the changed translation was updated
        $this->assertEquals('Welcome to our site', $enContent['pages.home.welcome']);
        $this->assertEquals('Bienvenido a nuestro sitio', $esContent['pages.home.welcome']);

        // Verify the unchanged translation remained the same
        $this->assertEquals('About Us', $enContent['pages.about.title']);
        $this->assertEquals('Acerca de nosotros', $esContent['pages.about.title']);
    }

    /** @test */
    public function it_detects_multiple_changed_values_and_retranslates_them()
    {
        // Create existing translation files with initial translations
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.about.title' => 'About Us'
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.about.title' => 'Acerca de nosotros'
        ]));

        // Create blade file with changed values
        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome to our amazing site')\n" .
            "@text('pages.about.title', 'About Our Company')"
        );

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'pages.home.welcome' => [
                                    'en' => 'Welcome to our amazing site',
                                    'es' => 'Bienvenido a nuestro increíble sitio',
                                ],
                                'pages.about.title' => [
                                    'en' => 'About Our Company',
                                    'es' => 'Acerca de nuestra empresa',
                                ]
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('laratext:scan --write --resync --translator=openai')
            ->expectsOutput('Scanning project for translation keys...')
            ->expectsOutput('Found 2 unique keys.')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        // Verify both translations were updated
        $this->assertEquals('Welcome to our amazing site', $enContent['pages.home.welcome']);
        $this->assertEquals('About Our Company', $enContent['pages.about.title']);
        $this->assertEquals('Bienvenido a nuestro increíble sitio', $esContent['pages.home.welcome']);
        $this->assertEquals('Acerca de nuestra empresa', $esContent['pages.about.title']);
    }

    /** @test */
    public function it_does_not_retranslate_unchanged_values()
    {
        // Create existing translation files
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.about.title' => 'About Us'
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.about.title' => 'Acerca de nosotros'
        ]));

        // Create blade file with same values (no changes)
        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome')\n" .
            "@text('pages.about.title', 'About Us')"
        );

        $this->artisan('laratext:scan --write --translator=openai')
            ->expectsOutput('Scanning project for translation keys...')
            ->expectsOutput('No new keys to translate.')
            ->assertExitCode(0);

        // Verify HTTP was not called since no translation was needed
        Http::assertNothingSent();
    }

    /** @test */
    public function it_does_not_retranslate_changed_values_when_resync_is_disabled()
    {
        // Create existing translation files with initial translations
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.about.title' => 'About Us'
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.about.title' => 'Acerca de nosotros'
        ]));

        // Update the blade file with changed values
        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome to our amazing site')\n" .
            "@text('pages.about.title', 'About Our Company')"
        );

        // Run without --resync flag
        $this->artisan('laratext:scan --write --translator=openai')
            ->expectsOutput('Scanning project for translation keys...')
            ->expectsOutput('Found 2 unique keys.')
            ->expectsOutput('No new keys to translate.')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        // Verify translations were NOT updated (old values remain)
        $this->assertEquals('Welcome', $enContent['pages.home.welcome']);
        $this->assertEquals('About Us', $enContent['pages.about.title']);
        $this->assertEquals('Bienvenido', $esContent['pages.home.welcome']);
        $this->assertEquals('Acerca de nosotros', $esContent['pages.about.title']);

        // Verify HTTP was not called since no retranslation occurred
        Http::assertNothingSent();
    }

    /** @test */
    public function it_auto_generates_text_from_single_parameter_keys()
    {
        // Create a blade file with single-parameter @text calls
        File::put(resource_path('views/test.blade.php'),
            "@text('hello_mate');\n" .
            "@text('welcome_back');\n" .
            "text('pages.contact_us');\n" .
            "Text::get('user.first_name');"
        );

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'hello_mate' => [
                                    'en' => 'Hello Mate',
                                    'es' => 'Hola Amigo',
                                ],
                                'welcome_back' => [
                                    'en' => 'Welcome Back',
                                    'es' => 'Bienvenido De Vuelta',
                                ],
                                'pages.contact_us' => [
                                    'en' => 'Contact Us',
                                    'es' => 'Contáctanos',
                                ],
                                'user.first_name' => [
                                    'en' => 'First Name',
                                    'es' => 'Nombre',
                                ]
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('laratext:scan --write --translator=openai')
            ->expectsOutput('Scanning project for translation keys...')
            ->expectsOutput('Found 4 unique keys.')
            ->expectsOutput('Translation file updated: ' . lang_path('en.json'))
            ->expectsOutput('Translation file updated: ' . lang_path('es.json'))
            ->expectsOutput('All translations processed.')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        // Verify auto-generated texts were used and translated
        $this->assertEquals('Hello Mate', $enContent['hello_mate']);
        $this->assertEquals('Welcome Back', $enContent['welcome_back']);
        $this->assertEquals('Contact Us', $enContent['pages.contact_us']);
        $this->assertEquals('First Name', $enContent['user.first_name']);

        $this->assertEquals('Hola Amigo', $esContent['hello_mate']);
        $this->assertEquals('Bienvenido De Vuelta', $esContent['welcome_back']);
        $this->assertEquals('Contáctanos', $esContent['pages.contact_us']);
        $this->assertEquals('Nombre', $esContent['user.first_name']);
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
