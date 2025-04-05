<?php

namespace EduLazaro\Laratext;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use EduLazaro\Laratext\Commands\MakeTranslatorCommand;
use EduLazaro\Laratext\Commands\ScanTranslationsCommand;

class LaratextServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        Blade::directive('text', function ($expression) {
            return "<?php echo \EduLazaro\Laratext\Text::get($expression); ?>";
        });

        $this->publishes([
            __DIR__.'/../config/texts.php' => config_path('texts.php'),
        ], 'texts');

        $this->loadHelpers();
    }

    /**
     * Register the helper functions.
     *
     * @return void
     */
    protected function loadHelpers()
    {
        require_once __DIR__ . '/helpers.php';
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {

        $this->commands([
            ScanTranslationsCommand::class,
            MakeTranslatorCommand::class,
        ]);
    }
}
