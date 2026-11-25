<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Text;

class SourceTextReplacementsTest extends TestCase
{
    /** @test */
    public function a_text_not_in_the_language_file_gets_the_capitalised_forms()
    {
        $this->assertSame(
            'Hola ana, Ana, ANA',
            Text::get('missing.greeting', 'Hola :name, :Name, :NAME', ['name' => 'ana'])
        );
    }

    /** @test */
    public function the_longest_placeholder_is_replaced_first()
    {
        $this->assertSame(
            'Ana / Ana López',
            Text::get('missing.names', ':name / :name_full', ['name' => 'Ana', 'name_full' => 'Ana López'])
        );
    }

    /** @test */
    public function a_closure_wraps_a_tag()
    {
        $this->assertSame(
            'Read the <a href="/terms">terms</a>',
            Text::get('missing.terms', 'Read the <link>terms</link>', [
                'link' => fn (string $inner) => '<a href="/terms">' . $inner . '</a>',
            ])
        );
    }
}
