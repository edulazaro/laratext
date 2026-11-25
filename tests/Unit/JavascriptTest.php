<?php

namespace EduLazaro\Laratext\Tests\Unit;

use EduLazaro\Laratext\Tests\TestCase;
use EduLazaro\Laratext\Text;
use Illuminate\Support\Facades\File;
use Illuminate\Translation\MessageSelector;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class JavascriptTest extends TestCase
{
    protected string $node;

    protected function setUp(): void
    {
        parent::setUp();

        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js is not installed.');
        }

        $this->node = $node;
    }

    protected function tearDown(): void
    {
        File::delete(lang_path('es.json'));

        parent::tearDown();
    }

    /** @test */
    public function the_javascript_unit_tests_pass()
    {
        $process = new Process([$this->node, '--test', 'tests/js/text.test.mjs'], dirname(__DIR__, 2));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
    }

    /** @test */
    public function javascript_chooses_plural_forms_like_laravel_in_every_language()
    {
        $locales = [];
        preg_match_all('/^  (\w+): \d+,$/m', File::get(dirname(__DIR__, 2) . '/resources/js/plural.js'), $locales);
        $locales = [...$locales[1], 'xx', null];

        $numbers = [0, 1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12, 13, 14, 15, 19, 20, 21, 22, 25, 100, 101, 102, 103, 111, 112, 0.5, 1.5, 2.5, -1, -2];

        $lines = [
            'a|b|c|d|e|f',
            '{0} none|[1,19] some|[20,*] many',
            '[*,1] few|[2,*] lots',
            '{1} uno|{2} dos|resto|otros',
            ' padded | spaced ',
            'only one',
        ];

        $cases = [];
        $expected = [];
        $selector = new MessageSelector;

        foreach ($locales as $i => $locale) {
            foreach ($numbers as $number) {
                foreach ($i < 12 ? $lines : [$lines[0]] as $line) {
                    $cases[] = [$line, $number, $locale];
                    $expected[] = $selector->choose($line, $number, $locale);
                }
            }
        }

        $this->assertSame($expected, $this->runParity(['choose' => $cases, 'replace' => [], 'translate' => []])['choose']);
    }

    /** @test */
    public function javascript_replaces_placeholders_like_laravel()
    {
        $cases = [
            ['Hola :name, :Name, :NAME', ['name' => 'ana']],
            [':name / :name_full', ['name' => 'Ana', 'name_full' => 'Ana López']],
            ['Vacío: [:value]', ['value' => null]],
            [':count elementos', ['count' => 3]],
            [':name y :Name', ['name' => 'élan']],
            [':word', ['word' => 'straße']],
            ['Sin marcadores', ['name' => 'x']],
            ['::name', ['name' => 'x']],
            [':Name y :name', ['Name' => 'Ana']],
            ['Nada que cambiar', []],
        ];

        $replace = new \ReflectionMethod(Text::class, 'makeReplacements');
        $expected = array_map(fn ($case) => $replace->invoke(null, $case[0], $case[1]), $cases);

        $this->assertSame($expected, $this->runParity(['choose' => [], 'replace' => $cases, 'translate' => []])['replace']);
    }

    /** @test */
    public function javascript_text_gives_what_text_gives_in_php()
    {
        $lines = [
            'k.plain' => 'Hola',
            'k.place' => 'Hola :name, :NAME',
            'k.plural' => 'un comentario|:count comentarios',
            'k.ranges' => '{0} ninguno|[1,*] :count en total',
            'k.empty' => '',
            'k.same' => 'k.same',
            'k.pipe' => 'Ctrl|Alt',
        ];

        File::ensureDirectoryExists(lang_path());
        File::put(lang_path('es.json'), json_encode($lines));

        $queries = [
            ['k.plain', 'Hello', []],
            ['k.place', 'Hello :name', ['name' => 'ana']],
            ['k.plural', 'one|many', ['count' => 1]],
            ['k.plural', 'one|many', ['count' => 5]],
            ['k.plural', 'one|many', ['count' => '2']],
            ['k.plural', 'one|many', ['count' => 1.5]],
            ['k.ranges', 'x', ['count' => 0]],
            ['k.ranges', 'x', ['count' => 7]],
            ['k.empty', 'Fuente', []],
            ['k.same', 'Fuente', []],
            ['k.pipe', 'x', []],
            ['k.pipe', 'x', ['count' => 2]],
            ['k.missing', 'Nuevo :name', ['name' => 'ana']],
            ['k.missing', 'una cosa|:count cosas', ['count' => 1]],
            ['k.missing', 'una cosa|:count cosas', ['count' => 1.5]],
            ['k.missing', 'una cosa|:count cosas', ['count' => 'abc']],
            ['k.missing', '', []],
        ];

        $cases = [];
        $expected = [];

        foreach ($queries as [$key, $source, $replace]) {
            $cases[] = [$lines, 'es', $key, $source, $replace];
            $expected[] = Text::get($key, $source, $replace, 'es');
        }

        $this->assertSame($expected, $this->runParity(['choose' => [], 'replace' => [], 'translate' => $cases])['translate']);
    }

    protected function runParity(array $cases): array
    {
        $file = tempnam(sys_get_temp_dir(), 'laratext');
        File::put($file, json_encode($cases));

        try {
            $process = new Process([$this->node, 'tests/js/parity.mjs', $file], dirname(__DIR__, 2));
            $process->mustRun();

            return json_decode($process->getOutput(), true);
        } finally {
            File::delete($file);
        }
    }
}
