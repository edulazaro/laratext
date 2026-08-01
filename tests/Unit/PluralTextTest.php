<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PluralTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(lang_path());

        foreach (['en', 'es', 'ru'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }
    }

    protected function tearDown(): void
    {
        foreach (['en', 'es', 'ru'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }

        parent::tearDown();
    }

    protected function writeLangFile(string $lang, array $translations): void
    {
        File::put(lang_path("{$lang}.json"), json_encode($translations));
    }

    public function test_a_plain_text_is_unaffected()
    {
        $this->assertSame('Welcome, John!', text('welcome', 'Welcome, :name!', ['name' => 'John']));
    }

    public function test_a_count_without_plural_forms_is_just_a_replacement()
    {
        $this->assertSame('You have 5 items', text('items', 'You have :count items', ['count' => 5]));
    }

    public function test_it_chooses_the_form_from_the_source_text()
    {
        $line = 'One item|You have :count items';

        $this->assertSame('One item', text('items', $line, ['count' => 1]));
        $this->assertSame('You have 5 items', text('items', $line, ['count' => 5]));
        $this->assertSame('You have 0 items', text('items', $line, ['count' => 0]));
    }

    public function test_it_chooses_the_form_from_the_language_file()
    {
        app()->setLocale('es');
        $this->writeLangFile('es', ['items' => 'Un artículo|Tienes :count artículos']);

        $this->assertSame('Un artículo', text('items', 'One item|:count items', ['count' => 1]));
        $this->assertSame('Tienes 5 artículos', text('items', 'One item|:count items', ['count' => 5]));
    }

    public function test_it_supports_explicit_ranges()
    {
        $line = '{0} No items|[1,19] You have :count items|[20,*] You have plenty';

        $this->assertSame('No items', text('items', $line, ['count' => 0]));
        $this->assertSame('You have 7 items', text('items', $line, ['count' => 7]));
        $this->assertSame('You have plenty', text('items', $line, ['count' => 50]));
    }

    public function test_it_supports_languages_with_three_forms()
    {
        app()->setLocale('ru');
        $this->writeLangFile('ru', ['apples' => 'яблоко|яблока|яблок']);

        $this->assertSame('яблоко', text('apples', 'apple|apples', ['count' => 1]));
        $this->assertSame('яблока', text('apples', 'apple|apples', ['count' => 3]));
        $this->assertSame('яблок', text('apples', 'apple|apples', ['count' => 20]));
    }

    public function test_a_pipe_without_a_count_is_left_alone()
    {
        $this->assertSame('Ctrl|Alt', text('shortcut', 'Ctrl|Alt'));
    }

    public function test_a_pipe_with_a_non_numeric_count_is_left_alone()
    {
        $this->assertSame('Ctrl|Alt', text('shortcut', 'Ctrl|Alt', ['count' => 'many']));
    }

    public function test_a_replaced_value_containing_a_pipe_is_not_a_plural()
    {
        app()->setLocale('en');
        $this->writeLangFile('en', ['shortcut' => 'Press :keys']);

        $this->assertSame('Press Ctrl|Alt', text('shortcut', 'Press :keys', ['keys' => 'Ctrl|Alt', 'count' => 2]));
    }
}
