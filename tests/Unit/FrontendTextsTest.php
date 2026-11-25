<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Text;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

class FrontendTextsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(lang_path());
        File::put(lang_path('es.json'), json_encode([
            'header' => 'Cabecera',
            'header.search' => 'Buscar',
            'headers.other' => 'Otra',
            'cart.total' => 'Total: :amount',
            'cart.evil' => '</script><script>alert(1)</script>',
        ]));
        File::put(lang_path('fr.json'), json_encode(['header.search' => 'Chercher']));

        app()->setLocale('es');
    }

    protected function tearDown(): void
    {
        File::delete(lang_path('es.json'));
        File::delete(lang_path('fr.json'));

        parent::tearDown();
    }

    /** @test */
    public function it_returns_the_keys_under_a_prefix_in_the_current_locale()
    {
        $this->assertSame(
            ['locale' => 'es', 'lines' => ['header' => 'Cabecera', 'header.search' => 'Buscar']],
            Text::frontend('header')
        );
    }

    /** @test */
    public function it_takes_several_prefixes_another_locale_or_everything()
    {
        $this->assertSame(['header.search' => 'Chercher'], Text::frontend(['header', 'cart'], 'fr')['lines']);
        $this->assertSame('fr', Text::frontend('header', 'fr')['locale']);
        $this->assertCount(5, Text::frontend('*')['lines']);
        $this->assertSame([], Text::frontend('missing')['lines']);
    }

    /** @test */
    public function the_script_tag_holds_json_a_translation_cannot_break_out_of()
    {
        $html = (string) Text::script('cart');

        $this->assertStringStartsWith('<script type="application/json" data-laratext>', $html);
        $this->assertSame(1, substr_count($html, '</script>'));

        $json = substr($html, strlen('<script type="application/json" data-laratext>'), -strlen('</script>'));
        $this->assertSame(
            ['locale' => 'es', 'lines' => ['cart.total' => 'Total: :amount', 'cart.evil' => '</script><script>alert(1)</script>']],
            json_decode($json, true)
        );
    }

    /** @test */
    public function an_empty_list_prints_an_object()
    {
        $this->assertStringContainsString('"lines":{}', (string) Text::script('missing'));
    }

    /** @test */
    public function the_blade_directive_prints_the_script_tag()
    {
        $html = Blade::render("@texts(['header'])");

        $this->assertStringContainsString('data-laratext>{"locale":"es","lines":{"header":"Cabecera","header.search":"Buscar"}}', $html);
    }
}
