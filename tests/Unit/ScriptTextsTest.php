<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\ScriptTexts;
use EduLazaro\Laratext\Tests\TestCase;

class ScriptTextsTest extends TestCase
{
    protected function extract(string $code, array $functions = ['text', '$text']): array
    {
        return (new ScriptTexts($functions))->extract($code);
    }

    /** @test */
    public function it_reads_a_call_between_jsx_text_full_of_apostrophes()
    {
        $code = <<<'JSX'
            <p>L'Andorra és petita, d'acord?</p>
            <h1>{text('home.title', 'La guía más completa')}</h1>
            <span>Què n'opines de l'estiu?</span>
            {text('home.lead', "Tot el que cal saber")}
            JSX;

        $result = $this->extract($code);

        $this->assertSame([
            'home.title' => 'La guía más completa',
            'home.lead' => 'Tot el que cal saber',
        ], $result['texts']);
        $this->assertSame([], $result['unreadable']);
    }

    /** @test */
    public function it_reads_escaped_quotes_and_javascript_escapes()
    {
        $code = <<<'JS'
            text('a', 'l\'Andorra');
            text('b', "Diu \"hola\"");
            text('c', 'Line one\nLine two\tTabbed');
            text('d', 'à \u{1F600} 😀 \x41');
            text('e', 'Back\\slash');
            text('f', `It's fine`);
            JS;

        $texts = $this->extract($code)['texts'];

        $this->assertSame("l'Andorra", $texts['a']);
        $this->assertSame('Diu "hola"', $texts['b']);
        $this->assertSame("Line one\nLine two\tTabbed", $texts['c']);
        $this->assertSame('à 😀 😀 A', $texts['d']);
        $this->assertSame('Back\\slash', $texts['e']);
        $this->assertSame("It's fine", $texts['f']);
    }

    /** @test */
    public function it_reads_a_call_split_over_several_lines_with_a_third_argument()
    {
        $code = <<<'JS'
            const label = text(
              'feed.comments',
              'Un comentario|:count comentarios',
              { count: post.comments_count },
            );
            JS;

        $this->assertSame(
            ['feed.comments' => 'Un comentario|:count comentarios'],
            $this->extract($code)['texts']
        );
    }

    /** @test */
    public function it_reads_vue_templates_and_svelte_markup()
    {
        $vue = <<<'VUE'
            <template>
              <input :placeholder="$text('search.placeholder', 'Busca l\'article')" />
              <p>{{ $text("search.empty", "No hi ha resultats") }}</p>
            </template>
            VUE;

        $svelte = <<<'SVELTE'
            <script>
              const text = useText();
            </script>
            <button>{text('cart.add', 'Afegeix')}</button>
            SVELTE;

        $this->assertSame([
            'search.placeholder' => "Busca l'article",
            'search.empty' => 'No hi ha resultats',
        ], $this->extract($vue)['texts']);

        $this->assertSame(['cart.add' => 'Afegeix'], $this->extract($svelte)['texts']);
    }

    /** @test */
    public function it_reads_this_dollar_text_from_an_alpine_component()
    {
        $code = "Alpine.data('cart', () => ({ label() { return this.\$text('cart.total', 'Total'); } }));";

        $this->assertSame(['cart.total' => 'Total'], $this->extract($code)['texts']);
    }

    /** @test */
    public function it_ignores_names_that_only_end_like_the_function()
    {
        $code = <<<'JS'
            setText('a', 'b');
            post.text('c', 'd');
            const body = await response.text();
            context('e', 'f');
            const text = useText();
            JS;

        $result = $this->extract($code);

        $this->assertSame([], $result['texts']);
        $this->assertSame([], $result['unreadable']);
    }

    /** @test */
    public function it_reads_custom_function_names_without_catching_longer_ones()
    {
        $code = <<<'JS'
            t('landing.beta', 'Beta');
            set('x', 'y');
            'a,b'.split(',');
            import('./page.js');
            JS;

        $result = $this->extract($code, ['t']);

        $this->assertSame(['landing.beta' => 'Beta'], $result['texts']);
        $this->assertSame([], $result['unreadable']);
    }

    /** @test */
    public function it_reports_calls_it_cannot_read_with_their_line()
    {
        $code = <<<'JS'
            text('ok', 'Fine');
            text(`rooms.${type}`, 'Room');
            text('greeting', `Hola ${name}`);
            text(key, 'Text');
            text('joined', 'Hola ' + name);
            JS;

        $result = $this->extract($code);

        $this->assertSame(['ok' => 'Fine'], $result['texts']);
        $this->assertSame([2, 3, 4, 5], array_column($result['unreadable'], 'line'));
        $this->assertStringStartsWith('text(key', $result['unreadable'][2]['call']);
    }

    /** @test */
    public function a_definition_is_not_reported_as_a_call()
    {
        $code = "export function text(key, source = '', replace = {}) {\n  return key;\n}";

        $this->assertSame([], $this->extract($code)['unreadable']);
    }

    /** @test */
    public function a_one_argument_call_gets_its_text_from_the_key()
    {
        $texts = $this->extract("{text('pages.contact_us')}")['texts'];

        $this->assertSame(['pages.contact_us' => 'Contact Us'], $texts);
    }

    /** @test */
    public function it_reads_a_multi_line_call_with_windows_line_endings()
    {
        $code = "const label = text(\r\n  'cart.empty',\r\n  'El carro està buit'\r\n);\r\n";

        $this->assertSame(['cart.empty' => 'El carro està buit'], $this->extract($code)['texts']);
    }

    /** @test */
    public function a_file_that_is_not_valid_utf8_is_reported_not_half_read()
    {
        $result = $this->extract("text('a', 'Caf\xE9');");

        $this->assertSame([], $result['texts']);
        $this->assertSame('Not valid UTF-8', $result['unreadable'][0]['call']);
    }

    /** @test */
    public function a_call_written_in_a_comment_is_reported_rather_than_skipped_in_silence()
    {
        $code = "/** Usage: text(key, source) inside a component. */\ntext('ok', 'Fine');";

        $result = $this->extract($code);

        $this->assertSame(['ok' => 'Fine'], $result['texts']);
        $this->assertSame([1], array_column($result['unreadable'], 'line'));
    }

    /** @test */
    public function a_double_quoted_key_with_a_dollar_is_literal_in_javascript()
    {
        $this->assertSame(['price.$' => 'Price'], $this->extract('text("price.$", "Price")')['texts']);
    }
}
