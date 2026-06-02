<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry;

use Dmitryisaenko\LaraFoundry\Auth\Actions\CreateNewUser;
use Dmitryisaenko\LaraFoundry\Auth\Actions\ResetUserPassword;
use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserPassword;
use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\EnsureAccountIsActive;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\ReconcileTrackedSession;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\LogFailedLoginAttempt;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\RecordUserSession;
use Dmitryisaenko\LaraFoundry\Auth\Support\UserAgentDeviceResolver;
use Dmitryisaenko\LaraFoundry\Console\Commands\InstallCommand;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class LaraFoundryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry.php', 'larafoundry');

        // Default, dependency-free device fingerprinting. A host may rebind this
        // contract to a richer parser.
        $this->app->bind(DeviceFingerprintResolver::class, UserAgentDeviceResolver::class);

        // Replace Fortify's scaffolded actions with the core's hardened ones.
        $this->app->singleton(CreatesNewUsers::class, CreateNewUser::class);
        $this->app->singleton(ResetsUserPasswords::class, ResetUserPassword::class);
        $this->app->singleton(UpdatesUserPasswords::class, UpdateUserPassword::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'larafoundry');

        $this->registerAuthMiddleware();
        $this->registerAuthEventListeners();
        $this->localizeAuthMail();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    /**
     * Register the core's auth middleware aliases.
     *
     * `larafoundry.account.active` enforces blocked/deleted gating;
     * `larafoundry.session.reconcile` evicts revoked sessions. The host applies
     * both to its authenticated route group.
     */
    protected function registerAuthMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('larafoundry.account.active', EnsureAccountIsActive::class);
        $router->aliasMiddleware('larafoundry.session.reconcile', ReconcileTrackedSession::class);
    }

    protected function registerAuthEventListeners(): void
    {
        // Session tracking rides the framework Login event, so it captures every
        // login path — native, OAuth, registration auto-login, remember-me, 2FA
        // — through one writer, leaving Fortify's default pipeline untouched.
        Event::listen(Login::class, [RecordUserSession::class, 'handle']);

        Event::listen(Failed::class, [LogFailedLoginAttempt::class, 'handleFailed']);
        Event::listen(Lockout::class, [LogFailedLoginAttempt::class, 'handleLockout']);
    }

    /**
     * Localise the email-verification mail through the core's translations.
     *
     * Decision D3-loc: the core owns the wording (so it ships translated out of
     * the box and follows the locale standard), while Fortify/Laravel still own
     * sending and link generation. The host overrides text via the published
     * lang files and layout via `vendor:publish --tag=laravel-mail`.
     */
    protected function localizeAuthMail(): void
    {
        VerifyEmail::toMailUsing(function (mixed $notifiable, string $url) {
            return (new MailMessage)
                ->subject(__('larafoundry::auth.verify_email.subject'))
                ->line(__('larafoundry::auth.verify_email.intro'))
                ->action(__('larafoundry::auth.verify_email.action'), $url)
                ->line(__('larafoundry::auth.verify_email.outro'));
        });
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/larafoundry.php' => config_path('larafoundry.php'),
        ], 'larafoundry-config');

        $this->publishes([
            __DIR__.'/../resources/js/Pages' => resource_path('js/Pages'),
        ], 'larafoundry-pages');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/larafoundry'),
        ], 'larafoundry-lang');
    }
}
