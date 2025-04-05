<?php

namespace EduLazaro\Laratext\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeTranslatorCommand extends Command
{
    protected $signature = 'make:translator {name : The name of the translator}';

    protected $description = 'Create a new translator class';

    public function handle(): void
    {
        $name = trim($this->argument('name'));

        if (!preg_match('/^[A-Z][A-Za-z0-9_]+$/', $name)) {
            $this->error('The name must be a valid class name (StudlyCase).');
            return;
        }

        $namespace = 'App\\Translators';
        $className = $name;
        $path = app_path('Translators/' . $className . '.php');

        if (file_exists($path)) {
            $this->error('❌ Translator already exists!');
            return;
        }

        $stubPath = __DIR__ . '/stubs/translator.stub';

        if (!file_exists($stubPath)) {
            $this->error('❌ Stub file not found: ' . $stubPath);
            return;
        }

        $stub = file_get_contents($stubPath);

        $content = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $className],
            $stub
        );

        (new Filesystem)->ensureDirectoryExists(app_path('Translators'));
        file_put_contents($path, $content);

        $this->info("✅ Translator created successfully at: {$path}");
        $this->info("💡 Tip: Add your translator to the texts.php config to use it!");
    }
}
