<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry;

use Dmitryisaenko\LaraFoundry\Console\Commands\InstallCommand;
use Illuminate\Support\ServiceProvider;

class LaraFoundryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry.php', 'larafoundry');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/larafoundry.php' => config_path('larafoundry.php'),
            ], 'larafoundry-config');

            $this->publishes([
                __DIR__.'/../resources/js/Pages' => resource_path('js/Pages'),
            ], 'larafoundry-pages');
        }
    }
}
