<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class LiteralKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(lang_path());
        File::delete(lang_path('en.json'));
        File::delete(lang_path('es.json'));
        File::delete(lang_path('fr.json'));

        Http::fake();
    }

    protected function tearDown(): void
    {
        File::delete(resource_path('views/keys.blade.php'));

        foreach (['en', 'es', 'fr'] as $lang) {
            File::delete(lang_path("{$lang}.json"));
        }

        parent::tearDown();
    }

    protected function scan(string $code): array
    {
        File::put(resource_path('views/keys.blade.php'), $code);

        $command = app(\EduLazaro\Laratext\Commands\ScanTranslationsCommand::class);

        $method = new \ReflectionMethod($command, 'extractTextsFromFiles');
        $files = (new \ReflectionMethod($command, 'getProjectFiles'))->invoke($command);

        return $method->invoke($command, $files);
    }

    public function test_a_dollar_inside_single_quotes_is_a_valid_key()
    {
        $keys = $this->scan("<?php text('hol\$a', 'Hello');");

        $this->assertArrayHasKey('hol$a', $keys);
        $this->assertSame('Hello', $keys['hol$a']);
    }

    public function test_a_concatenated_key_is_skipped()
    {
        $keys = $this->scan("<?php text('rooms.' . \$type, ucfirst(\$type));");

        foreach (array_keys($keys) as $key) {
            $this->assertStringNotContainsString('rooms.', $key);
        }
    }

    public function test_an_interpolated_key_is_skipped()
    {
        $keys = $this->scan('<?php text("activity.{$this->type->value}", "Something happened");');

        foreach (array_keys($keys) as $key) {
            $this->assertStringNotContainsString('activity.', $key);
        }
    }

    public function test_an_interpolated_key_is_skipped_in_the_single_argument_form()
    {
        $keys = $this->scan('<?php text("activity.{$this->type->value}");');

        foreach (array_keys($keys) as $key) {
            $this->assertStringNotContainsString('activity.', $key);
        }
    }

    public function test_double_quotes_without_variables_still_work()
    {
        $keys = $this->scan('<?php text("plain.key", "Plain value");');

        $this->assertArrayHasKey('plain.key', $keys);
    }

    /**
     * The regression that motivated all of this: a dynamic key used to make the
     * match run past the call and swallow the valid ones that followed.
     */
    public function test_a_dynamic_key_does_not_swallow_the_calls_after_it()
    {
        $keys = $this->scan(<<<'BLADE'
        <?php text('rooms.' . $image->label, ucfirst($image->label)); ?>
        <a href="foo">bar</a>
        @text('web.property.image', 'Image')
        @text('web.property.plan', 'Plan')
        BLADE);

        $this->assertArrayHasKey('web.property.image', $keys);
        $this->assertArrayHasKey('web.property.plan', $keys);
        $this->assertSame('Image', $keys['web.property.image']);

        foreach (array_keys($keys) as $key) {
            $this->assertStringNotContainsString('<a href', $key, 'No key may contain code');
            $this->assertStringNotContainsString("\n", $key, 'No key may span lines');
        }
    }

    public function test_the_text_after_the_comma_may_still_span_lines()
    {
        $keys = $this->scan("<?php text('multi', 'First line\nsecond line');");

        $this->assertArrayHasKey('multi', $keys);
        $this->assertStringContainsString("\n", $keys['multi']);
    }

}
