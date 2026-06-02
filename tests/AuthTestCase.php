<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests;

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;

/**
 * Base case for the auth (phase 1.1) suite.
 *
 * Kept separate from the shared {@see TestCase} so its database/migration and
 * Fortify/Socialite wiring is scoped to auth tests only — the rest of the suite
 * (middleware/unit) keeps its lean, DB-less setup. In particular,
 * defineDatabaseMigrations() here must NOT leak onto tests that flip the app
 * environment to "production", or Testbench's migrate step prompts.
 */
abstract class AuthTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SocialiteServiceProvider::class,
            FortifyServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
    }

    /**
     * Load the host-skeleton + Fortify-2FA migrations BEFORE the package's
     * 2026_* ALTER migration (the service provider loads that via
     * loadMigrationsFrom). Filenames sort the base tables ahead of the ALTER.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
