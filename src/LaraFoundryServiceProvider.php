<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry;

use Dmitryisaenko\LaraFoundry\Auth\Actions\CreateNewUser;
use Dmitryisaenko\LaraFoundry\Auth\Actions\ResetUserPassword;
use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserPassword;
use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\EnsureAccountIsActive;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\TrackSessionActivity;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\LogFailedLoginAttempt;
use Dmitryisaenko\LaraFoundry\Auth\Support\UserAgentDeviceResolver;
use Dmitryisaenko\LaraFoundry\Authorization\Console\Commands\SyncPermissionsCommand;
use Dmitryisaenko\LaraFoundry\Authorization\Gates\PermissionGateRegistrar;
use Dmitryisaenko\LaraFoundry\Authorization\Gates\RoleGates;
use Dmitryisaenko\LaraFoundry\Authorization\Listeners\AssignAuthenticatedRole;
use Dmitryisaenko\LaraFoundry\Authorization\Listeners\CloneCompanyRoles;
use Dmitryisaenko\LaraFoundry\Authorization\Listeners\RevokeAccessOnEmployeeRemoval;
use Dmitryisaenko\LaraFoundry\Console\Commands\InstallCommand;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware\EnsureActiveTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware\SetActiveTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\PersonalTenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\SessionTenantResolver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Registered;
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
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry-permissions.php', 'larafoundry-permissions');

        // Default, dependency-free device fingerprinting. A host may rebind this
        // contract to a richer parser.
        $this->app->bind(DeviceFingerprintResolver::class, UserAgentDeviceResolver::class);

        // Replace Fortify's scaffolded actions with the core's hardened ones.
        $this->app->singleton(CreatesNewUsers::class, CreateNewUser::class);
        $this->app->singleton(ResetsUserPasswords::class, ResetUserPassword::class);
        $this->app->singleton(UpdatesUserPasswords::class, UpdateUserPassword::class);

        $this->registerTenantResolver();
    }

    /**
     * Bind the active-tenant resolver to match the configured tenancy mode.
     *
     * `teams` reads the active company from the tracked session; `personal`
     * treats the user as its own tenant. Everything else depends on the
     * TenantResolver contract, so this binding is the only mode-aware seam (phase
     * 6 adds a token resolver here without touching call sites).
     */
    protected function registerTenantResolver(): void
    {
        $resolver = config('larafoundry.tenancy.mode') === 'personal'
            ? PersonalTenantResolver::class
            : SessionTenantResolver::class;

        $this->app->scoped(TenantResolver::class, $resolver);
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
        $this->registerTenancy();
        $this->registerAuthorization();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
                SyncPermissionsCommand::class,
            ]);
        }
    }

    /**
     * Wire authorization (phase 1.3): turn every catalog permission into a gate,
     * register the role-management gates, hook the lifecycle listeners and load
     * the role routes (teams only — roles are a company concept).
     *
     * Gates are always registered (global roles work in personal mode too); the
     * routes are not (no companies to manage there).
     */
    protected function registerAuthorization(): void
    {
        $this->app->make(PermissionGateRegistrar::class)->register();
        RoleGates::register();

        Event::listen(Registered::class, AssignAuthenticatedRole::class);
        Event::listen(CompanyCreated::class, CloneCompanyRoles::class);
        Event::listen(EmployeeRemoved::class, RevokeAccessOnEmployeeRemoval::class);

        if (config('larafoundry.tenancy.mode') !== 'personal') {
            $this->loadRoutesFrom(__DIR__.'/../routes/authorization.php');
        }
    }

    /**
     * Register the core's auth middleware aliases.
     *
     * `larafoundry.account.active` enforces blocked/deleted gating;
     * `larafoundry.session.track` records and refreshes the tracked session row
     * each request. The host applies both to its authenticated route group.
     */
    protected function registerAuthMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('larafoundry.account.active', EnsureAccountIsActive::class);
        $router->aliasMiddleware('larafoundry.session.track', TrackSessionActivity::class);
    }

    /**
     * Wire tenancy: middleware aliases (both modes) and the company routes
     * (teams only — in personal mode there are no companies to manage).
     */
    protected function registerTenancy(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('larafoundry.tenant.set', SetActiveTenant::class);
        $router->aliasMiddleware('larafoundry.tenant.required', EnsureActiveTenant::class);

        if (config('larafoundry.tenancy.mode') !== 'personal') {
            $this->loadRoutesFrom(__DIR__.'/../routes/tenancy.php');
        }
    }

    protected function registerAuthEventListeners(): void
    {
        // Session tracking is a per-request middleware (TrackSessionActivity),
        // not a Login listener — the login pipeline regenerates the session id
        // several times, so only a per-request pass sees the final, live id.
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
            __DIR__.'/../config/larafoundry-permissions.php' => config_path('larafoundry-permissions.php'),
        ], 'larafoundry-permissions');

        $this->publishes([
            __DIR__.'/../resources/js/Pages' => resource_path('js/Pages'),
        ], 'larafoundry-pages');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/larafoundry'),
        ], 'larafoundry-lang');
    }
}
