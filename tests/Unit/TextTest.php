<?php

use EduLazaro\Laratext\Text;
use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\Blade;

class TextTest extends TestCase
{
    /** @test */
    public function it_returns_default_value_if_translation_missing()
    {
        $result = Text::get('non.existent.key', 'Default Value');
        $this->assertEquals('Default Value', $result);
    }

    /** @test */
    public function it_replaces_placeholders_in_default_value_when_translation_missing()
    {
        $result = Text::get('non.existent.key', 'Hello, :name!', ['name' => 'Edu']);
        $this->assertEquals('Hello, Edu!', $result);
    }

    /** @test */
    public function it_replaces_placeholders_in_existing_translation()
    {
        // Fake translation with placeholder
        $langFile = lang_path('en.json');
        file_put_contents($langFile, json_encode([
            'greeting.message' => 'Hi, :name!',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $result = Text::get('greeting.message', 'Fallback :name', ['name' => 'Edu']);
        $this->assertEquals('Hi, Edu!', $result);
    }

    /** @test */
    public function it_returns_helper_function_value_if_translation_missing()
    {
        $result = text('non.existent.key', 'Default Helper');
        $this->assertEquals('Default Helper', $result);
    }

    /** @test */
    public function it_renders_blade_directive_with_default_value()
    {
        $blade = "@text('non.existent.key', 'Default Blade')";
        $rendered = Blade::render($blade);
        $this->assertEquals('Default Blade', $rendered);
    }

    /** @test */
    public function it_returns_existing_translation_from_file()
    {
        // Prepare a fake translation file
        $langFile = lang_path('en.json');
        file_put_contents($langFile, json_encode([
            'pages.home.welcome' => 'Welcome!',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $result = Text::get('pages.home.welcome', 'Default Value');
        $this->assertEquals('Welcome!', $result);

        $helperResult = text('pages.home.welcome', 'Default Helper');
        $this->assertEquals('Welcome!', $helperResult);

        $blade = "@text('pages.home.welcome', 'Default Blade')";
        $rendered = Blade::render($blade);
        $this->assertStringContainsString('Welcome!', $rendered);
    }

    /** @test */
    public function it_returns_existing_translation_from_file_with_placeholders()
    {
        // Prepare a fake translation file with placeholders
        $langFile = lang_path('en.json');
        file_put_contents($langFile, json_encode([
            'pages.home.welcome' => 'Welcome, :name!',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Text::get with replacements
        $result = Text::get('pages.home.welcome', 'Default :name', ['name' => 'Edu']);
        $this->assertEquals('Welcome, Edu!', $result);

        // text() helper with replacements
        $helperResult = text('pages.home.welcome', 'Default :name', ['name' => 'Edu']);
        $this->assertEquals('Welcome, Edu!', $helperResult);

        // @text Blade directive with replacements
        $blade = "@text('pages.home.welcome', 'Default :name', ['name' => 'Edu'])";
        $rendered = Blade::render($blade);
        $this->assertEquals('Welcome, Edu!', $rendered);
    }
}