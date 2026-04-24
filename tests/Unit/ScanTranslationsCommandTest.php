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
        File::delete(lang_path('fr.json'));

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
    public function it_retranslates_drifted_keys_by_default()
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
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.about.title' => 'À propos'
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
                                    'fr' => 'Bienvenue sur notre site',
                                ]
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('laratext:scan --write --translator=openai')
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
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.about.title' => 'À propos'
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
                                    'fr' => 'Bienvenue sur notre super site',
                                ],
                                'pages.about.title' => [
                                    'en' => 'About Our Company',
                                    'es' => 'Acerca de nuestra empresa',
                                    'fr' => 'À propos de notre entreprise',
                                ]
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('laratext:scan --write --translator=openai')
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
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.about.title' => 'À propos'
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
    public function it_does_not_retranslate_changed_values_when_only_missing_is_enabled()
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
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.about.title' => 'À propos'
        ]));

        // Update the blade file with changed values
        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome to our amazing site')\n" .
            "@text('pages.about.title', 'About Our Company')"
        );

        // Run with --only-missing: drift is detected and warned, but NOT retranslated
        $this->artisan('laratext:scan --write --only-missing --translator=openai')
            ->expectsOutput('Scanning project for translation keys...')
            ->expectsOutput('Found 2 unique keys.')
            ->expectsOutputToContain('key(s) have an updated source text')
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

    /** @test */
    public function it_warns_about_stale_source_text_without_calling_translator_when_only_missing()
    {
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
        ]));
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
        ]));

        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome to our site')"
        );

        Http::fake();

        $this->artisan('laratext:scan --write --only-missing --translator=openai')
            ->expectsOutputToContain('key(s) have an updated source text')
            ->expectsOutputToContain('pages.home.welcome')
            ->expectsOutputToContain('old: "Welcome"')
            ->expectsOutputToContain('new: "Welcome to our site"')
            ->expectsOutput('No new keys to translate.')
            ->assertExitCode(0);

        Http::assertNothingSent();

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        $this->assertEquals('Welcome', $enContent['pages.home.welcome']);
        $this->assertEquals('Bienvenido', $esContent['pages.home.welcome']);
    }

    /** @test */
    public function it_resync_retranslates_every_key_from_scratch()
    {
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.about.title' => 'About Us',
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.about.title' => 'Acerca de nosotros',
        ]));
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.about.title' => 'À propos',
        ]));

        // No drift in code; source values match what is on disk.
        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome')\n" .
            "@text('pages.about.title', 'About Us')"
        );

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'pages.home.welcome' => [
                                    'en' => 'Welcome',
                                    'es' => 'Te damos la bienvenida',
                                    'fr' => 'Soyez le bienvenu',
                                ],
                                'pages.about.title' => [
                                    'en' => 'About Us',
                                    'es' => 'Sobre nosotros',
                                    'fr' => 'À notre sujet',
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        // --resync must retranslate every key, even keys that are in sync on disk.
        $this->artisan('laratext:scan --write --resync --translator=openai')
            ->assertExitCode(0);

        $esContent = json_decode(File::get(lang_path('es.json')), true);
        $frContent = json_decode(File::get(lang_path('fr.json')), true);

        $this->assertEquals('Te damos la bienvenida', $esContent['pages.home.welcome']);
        $this->assertEquals('Sobre nosotros', $esContent['pages.about.title']);
        $this->assertEquals('Soyez le bienvenu', $frContent['pages.home.welcome']);
        $this->assertEquals('À notre sujet', $frContent['pages.about.title']);
    }

    /** @test */
    public function it_reports_no_changes_when_all_keys_are_in_sync()
    {
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
        ]));
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
        ]));

        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome')"
        );

        Http::fake();

        $this->artisan('laratext:scan --write --translator=openai')
            ->expectsOutput('No new keys to translate.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    /** @test */
    public function it_warns_about_orphan_keys_without_prune()
    {
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.legacy.removed' => 'Old Text',
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.legacy.removed' => 'Texto viejo',
        ]));
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.legacy.removed' => 'Ancien texte',
        ]));

        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome')"
        );

        $this->artisan('laratext:scan --write --translator=openai')
            ->expectsOutputToContain('key(s) found in lang files but no longer in code')
            ->expectsOutputToContain('pages.legacy.removed')
            ->expectsOutputToContain('Run with --prune --write to remove them')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        $this->assertArrayHasKey('pages.legacy.removed', $enContent);
        $this->assertArrayHasKey('pages.legacy.removed', $esContent);
    }

    /** @test */
    public function it_lists_orphans_with_prune_but_does_not_remove_without_write()
    {
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.legacy.removed' => 'Old Text',
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.legacy.removed' => 'Texto viejo',
        ]));
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.legacy.removed' => 'Ancien texte',
        ]));

        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome')"
        );

        $this->artisan('laratext:scan --prune --translator=openai')
            ->expectsOutputToContain('pages.legacy.removed')
            ->expectsOutputToContain('Run with --write to actually remove them')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);

        $this->assertArrayHasKey('pages.legacy.removed', $enContent);
        $this->assertArrayHasKey('pages.legacy.removed', $esContent);
    }

    /** @test */
    public function it_removes_orphans_from_all_lang_files_with_prune_and_write()
    {
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.legacy.removed' => 'Old Text',
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.legacy.removed' => 'Texto viejo',
        ]));
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.legacy.removed' => 'Ancien texte',
        ]));

        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome')"
        );

        Http::fake();

        $this->artisan('laratext:scan --write --prune --translator=openai')
            ->expectsOutputToContain('Pruned 1 orphan key(s)')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);
        $frContent = json_decode(File::get(lang_path('fr.json')), true);

        $this->assertArrayNotHasKey('pages.legacy.removed', $enContent);
        $this->assertArrayNotHasKey('pages.legacy.removed', $esContent);
        $this->assertArrayNotHasKey('pages.legacy.removed', $frContent);
        $this->assertArrayHasKey('pages.home.welcome', $enContent);
        $this->assertArrayHasKey('pages.home.welcome', $esContent);
        $this->assertArrayHasKey('pages.home.welcome', $frContent);

        Http::assertNothingSent();
    }

    /** @test */
    public function it_handles_new_stale_and_orphan_keys_in_a_single_run()
    {
        File::put(lang_path('en.json'), json_encode([
            'pages.home.welcome' => 'Welcome',
            'pages.legacy.removed' => 'Old Text',
        ]));
        File::put(lang_path('es.json'), json_encode([
            'pages.home.welcome' => 'Bienvenido',
            'pages.legacy.removed' => 'Texto viejo',
        ]));
        File::put(lang_path('fr.json'), json_encode([
            'pages.home.welcome' => 'Bienvenue',
            'pages.legacy.removed' => 'Ancien texte',
        ]));

        File::put(resource_path('views/test.blade.php'),
            "@text('pages.home.welcome', 'Welcome to our site')\n" .
            "@text('pages.contact.title', 'Contact')"
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
                                    'fr' => 'Bienvenue sur notre site',
                                ],
                                'pages.contact.title' => [
                                    'en' => 'Contact',
                                    'es' => 'Contacto',
                                    'fr' => 'Contact',
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('laratext:scan --write --prune --translator=openai')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);
        $frContent = json_decode(File::get(lang_path('fr.json')), true);

        $this->assertEquals('Welcome to our site', $enContent['pages.home.welcome']);
        $this->assertEquals('Bienvenido a nuestro sitio', $esContent['pages.home.welcome']);
        $this->assertEquals('Bienvenue sur notre site', $frContent['pages.home.welcome']);
        $this->assertEquals('Contact', $enContent['pages.contact.title']);
        $this->assertEquals('Contacto', $esContent['pages.contact.title']);
        $this->assertArrayNotHasKey('pages.legacy.removed', $enContent);
        $this->assertArrayNotHasKey('pages.legacy.removed', $esContent);
        $this->assertArrayNotHasKey('pages.legacy.removed', $frContent);
    }

    /** @test */
    public function it_does_not_prune_keys_still_referenced_via_single_argument_form()
    {
        File::put(lang_path('en.json'), json_encode([
            'hello_mate' => 'Hello Mate',
        ]));
        File::put(lang_path('es.json'), json_encode([
            'hello_mate' => 'Hola Amigo',
        ]));
        File::put(lang_path('fr.json'), json_encode([
            'hello_mate' => 'Salut Mon Pote',
        ]));

        File::put(resource_path('views/test.blade.php'),
            "@text('hello_mate');"
        );

        Http::fake();

        $this->artisan('laratext:scan --write --prune --translator=openai')
            ->assertExitCode(0);

        $enContent = json_decode(File::get(lang_path('en.json')), true);
        $esContent = json_decode(File::get(lang_path('es.json')), true);
        $frContent = json_decode(File::get(lang_path('fr.json')), true);

        $this->assertArrayHasKey('hello_mate', $enContent);
        $this->assertArrayHasKey('hello_mate', $esContent);
        $this->assertArrayHasKey('hello_mate', $frContent);
    }

    protected function tearDown(): void
    {
        // Clean up
        File::delete(resource_path('views/test.blade.php'));
        File::delete(lang_path('en.json'));
        File::delete(lang_path('es.json'));
        File::delete(lang_path('fr.json'));

        parent::tearDown();
    }
}
