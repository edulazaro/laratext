<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\TranslationLocks;
use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class ScanRespectsLocksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(lang_path());

        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
            File::delete(TranslationLocks::path($lang));
        }

        File::put(resource_path('views/test.blade.php'), "@text('save', 'Save changes')");
    }

    protected function tearDown(): void
    {
        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
            File::delete(TranslationLocks::path($lang));
        }

        parent::tearDown();
    }

    protected function fakeTranslator(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'save' => [
                                'en' => 'Save changes',
                                'es' => 'Guardar',
                                'fr' => 'Enregistrer',
                            ],
                        ]),
                    ],
                ]],
            ]),
        ]);
    }

    /**
     * The source text drifted, so without a lock the Spanish correction would
     * be overwritten by the translator.
     */
    protected function givenAHumanCorrectionInSpanish(): void
    {
        File::put(lang_path('en.json'), json_encode(['save' => 'Save']));
        File::put(lang_path('es.json'), json_encode(['save' => 'Guardar cambios']));
        File::put(lang_path('fr.json'), json_encode(['save' => 'Sauvegarder']));
    }

    public function test_without_a_lock_the_correction_is_overwritten()
    {
        $this->givenAHumanCorrectionInSpanish();
        $this->fakeTranslator();

        $this->artisan('laratext:scan --write --translator=openai')->assertExitCode(0);

        $es = json_decode(File::get(lang_path('es.json')), true);

        $this->assertSame('Guardar', $es['save'], 'This is the behaviour locks exist to prevent');
    }

    public function test_a_locked_key_survives_the_scan()
    {
        $this->givenAHumanCorrectionInSpanish();
        TranslationLocks::lock('es', ['save']);
        $this->fakeTranslator();

        $this->artisan('laratext:scan --write --translator=openai')->assertExitCode(0);

        $es = json_decode(File::get(lang_path('es.json')), true);
        $fr = json_decode(File::get(lang_path('fr.json')), true);

        $this->assertSame('Guardar cambios', $es['save'], 'The locked Spanish key must be untouched');
        $this->assertSame('Enregistrer', $fr['save'], 'French is not locked, so it is retranslated');
    }

    public function test_a_locked_key_is_not_pruned()
    {
        File::put(lang_path('es.json'), json_encode([
            'save' => 'Guardar cambios',
            'gone.from.code' => 'Ya no se usa',
        ]));

        TranslationLocks::lock('es', ['gone.from.code']);
        $this->fakeTranslator();

        $this->artisan('laratext:scan --write --prune --translator=openai')->assertExitCode(0);

        $es = json_decode(File::get(lang_path('es.json')), true);

        $this->assertArrayHasKey('gone.from.code', $es, 'A locked key must survive --prune');
    }

    public function test_a_key_locked_everywhere_is_never_sent_to_the_translator()
    {
        $this->givenAHumanCorrectionInSpanish();

        foreach (['en', 'es', 'fr'] as $lang) {
            TranslationLocks::lock($lang, ['save']);
        }

        $this->fakeTranslator();

        $this->artisan('laratext:scan --write --translator=openai')
            ->expectsOutputToContain('not sent to the translator')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }
}
