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
        $this->assertStringContainsString('Default Blade', $rendered);
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
}