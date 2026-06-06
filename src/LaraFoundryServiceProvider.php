<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry;

use Dmitryisaenko\LaraFoundry\ActivityLog\Contracts\GeoResolver;
use Dmitryisaenko\LaraFoundry\ActivityLog\Geo\IpApiGeoResolver;
use Dmitryisaenko\LaraFoundry\ActivityLog\Http\Middleware\LogActivity;
use Dmitryisaenko\LaraFoundry\ActivityLog\Listeners\LogRegisteredEvents;
use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\ActivityLog\Policies\ActivityLogPolicy;
use Dmitryisaenko\LaraFoundry\Auth\Actions\CreateNewUser;
use Dmitryisaenko\LaraFoundry\Auth\Actions\ResetUserPassword;
use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserPassword;
use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserProfileInformation;
use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\CheckPinLock;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\EnsureAccountIsActive;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\TrackSessionActivity;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\LogFailedLoginAttempt;
use Dmitryisaenko\LaraFoundry\Auth\Qr\Console\Commands\PruneSignInRequestsCommand;
use Dmitryisaenko\LaraFoundry\Auth\Support\UserAgentDeviceResolver;
use Dmitryisaenko\LaraFoundry\Authorization\Console\Commands\SyncPermissionsCommand;
use Dmitryisaenko\LaraFoundry\Authorization\Gates\PermissionGateRegistrar;
use Dmitryisaenko\LaraFoundry\Authorization\Gates\RoleGates;
use Dmitryisaenko\LaraFoundry\Authorization\Listeners\AssignAuthenticatedRole;
use Dmitryisaenko\LaraFoundry\Authorization\Listeners\CloneCompanyRoles;
use Dmitryisaenko\LaraFoundry\Authorization\Listeners\RevokeAccessOnEmployeeRemoval;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\RegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Support\ArrayPlanRepository;
use Dmitryisaenko\LaraFoundry\Billing\Support\DefaultRegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullEntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;
use Dmitryisaenko\LaraFoundry\Console\Commands\InstallCommand;
use Dmitryisaenko\LaraFoundry\Dashboard\Providers\CoreMetricsWidgetProvider;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureAdminOtpVerified;
use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureSuperAdmin;
use Dmitryisaenko\LaraFoundry\Http\Middleware\RedirectSuperAdminToConsole;
use Dmitryisaenko\LaraFoundry\Media\Contracts\AvatarGenerator;
use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Dmitryisaenko\LaraFoundry\Media\Support\FileStorageManager;
use Dmitryisaenko\LaraFoundry\Media\Support\ImageProcessor;
use Dmitryisaenko\LaraFoundry\Media\Support\InitialsAvatarGenerator;
use Dmitryisaenko\LaraFoundry\Navigation\Contracts\PolicyChecker;
use Dmitryisaenko\LaraFoundry\Navigation\Providers\AdminMenuProvider;
use Dmitryisaenko\LaraFoundry\Navigation\Providers\TenantMenuProvider;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Navigation\Support\RbacPolicyChecker;
use Dmitryisaenko\LaraFoundry\Notifications\Console\Commands\PruneNotificationsCommand;
use Dmitryisaenko\LaraFoundry\Notifications\Support\NotificationService;
use Dmitryisaenko\LaraFoundry\Profile\Providers\CoreUserProfileExporter;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataExportRegistry;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware\EnsureActiveTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware\SetActiveTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\PersonalTenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\SessionTenantResolver;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Dmitryisaenko\LaraFoundry\Tickets\Policies\TicketPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class LaraFoundryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry.php', 'larafoundry');
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry-permissions.php', 'larafoundry-permissions');
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry-activitylog.php', 'larafoundry-activitylog');
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry-media.php', 'larafoundry-media');
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry-notifications.php', 'larafoundry-notifications');
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry-tickets.php', 'larafoundry-tickets');

        // Default, dependency-free device fingerprinting. A host may rebind this
        // contract to a richer parser.
        $this->app->bind(DeviceFingerprintResolver::class, UserAgentDeviceResolver::class);

        // Replace Fortify's scaffolded actions with the core's hardened ones.
        $this->app->singleton(CreatesNewUsers::class, CreateNewUser::class);
        $this->app->singleton(ResetsUserPasswords::class, ResetUserPassword::class);
        $this->app->singleton(UpdatesUserPasswords::class, UpdateUserPassword::class);
        $this->app->singleton(UpdatesUserProfileInformation::class, UpdateUserProfileInformation::class);

        $this->registerTenantResolver();
        $this->registerActivityLog();
        $this->registerNavigation();
        $this->registerDashboard();
        $this->registerMedia();
        $this->registerBilling();
        $this->registerNotifications();
        $this->registerProfile();
    }

    /**
     * Wire the profile module (phase 5.1): the user-data export registry.
     *
     * Mirrors {@see registerNavigation()} / {@see registerDashboard()} — a
     * singleton registry seeded with the core's own provider, to which a host or
     * module adds more by resolving it and calling addProvider. The personal-data
     * export that consumes it is phase 5.3; the seam is wired now so it has
     * providers to read when it lands.
     */
    protected function registerProfile(): void
    {
        $this->app->singleton(UserDataExportRegistry::class, function ($app) {
            return (new UserDataExportRegistry)
                ->addProvider($app->make(CoreUserProfileExporter::class));
        });
    }

    /**
     * Wire the notification centre (phase 4.1).
     *
     * The host's domain pushes system notifications through NotificationService;
     * binding it as a singleton lets a host rebind a richer implementation
     * (extra channels, preferences) without touching call sites.
     */
    protected function registerNotifications(): void
    {
        $this->app->singleton(NotificationService::class);
    }

    /**
     * Wire the billing seam (phase 3.1).
     *
     * The FREE core ships only the seam: a gateway manager whose sole driver is
     * 'null' (takes no money), plus default region / plan / entitlement
     * implementations that keep the app fully open while billing is off. The paid
     * `larafoundry-billing` add-on rebinds these contracts and registers real
     * gateway drivers via PaymentGatewayManager::extend() — so nothing here knows
     * Stripe/Paddle, and no payment SDK enters the free core's dependencies.
     *
     * The manager is a singleton so add-on/host-registered drivers and their
     * resolved instances persist for the request; the contracts are bound (not
     * singleton) like the other swappable seams so a host override takes cleanly.
     */
    protected function registerBilling(): void
    {
        $this->app->singleton(PaymentGatewayManager::class, function ($app) {
            return new PaymentGatewayManager($app);
        });

        $this->app->bind(RegionContext::class, DefaultRegionContext::class);
        $this->app->bind(PlanRepositoryContract::class, ArrayPlanRepository::class);
        $this->app->bind(EntitlementResolver::class, NullEntitlementResolver::class);
    }

    /**
     * Wire the file/media library (phase 2.4).
     *
     * Every file operation depends on the MediaStorage contract (not
     * Storage::disk directly), so the disk is configuration and a future
     * polymorphic media library swaps in without touching call sites. The
     * intervention ImageManager driver comes from config (gd by default — the
     * common shared-hosting extension; imagick for hosts without gd); it is
     * resolved lazily, so the extension is only needed when an image is actually
     * processed. The avatar generator renders initials inline, so a missing
     * avatar needs no extension and no stored file.
     */
    protected function registerMedia(): void
    {
        $this->app->singleton(ImageManager::class, function () {
            $driver = config('larafoundry-media.image_driver', 'gd') === 'imagick'
                ? new ImagickDriver
                : new GdDriver;

            return new ImageManager($driver);
        });

        $this->app->singleton(ImageProcessor::class);
        $this->app->singleton(MediaStorage::class, FileStorageManager::class);
        $this->app->singleton(AvatarGenerator::class, InitialsAvatarGenerator::class);
    }

    /**
     * Wire navigation (phase 2.3): one shared MenuBuilder with the RBAC policy
     * checker and the core's own menu providers.
     *
     * The builder is a singleton so its per-request memo and the registered
     * providers persist across the request; a host adds its providers by
     * resolving the builder and calling addProvider (documented in the README).
     * Decision D-nav-a: building and filtering happen here, on the backend.
     */
    protected function registerNavigation(): void
    {
        $this->app->bind(PolicyChecker::class, RbacPolicyChecker::class);

        $this->app->singleton(MenuBuilder::class, function ($app) {
            return (new MenuBuilder)
                ->setPolicyChecker($app->make(PolicyChecker::class))
                ->addProvider($app->make(AdminMenuProvider::class))
                ->addProvider($app->make(TenantMenuProvider::class));
        });
    }

    /**
     * Wire the operator-console dashboard (phase 3.4): one shared DashboardBuilder
     * with the RBAC policy checker and the core's own metrics provider.
     *
     * The exact mirror of {@see registerNavigation()} — the dashboard seam copies
     * the navigation seam. The builder is a singleton so its per-request memo and
     * the registered providers persist; a host (or the paid billing add-on) adds
     * its widgets by resolving the builder and calling addProvider, exactly the way
     * a menu provider is added. The PolicyChecker is shared with navigation: "can
     * this user see this slug" is one question on both seams.
     */
    protected function registerDashboard(): void
    {
        $this->app->singleton(DashboardBuilder::class, function ($app) {
            return (new DashboardBuilder)
                ->setPolicyChecker($app->make(PolicyChecker::class))
                ->addProvider($app->make(CoreMetricsWidgetProvider::class));
        });
    }

    /**
     * Wire the activity log (phase 2.1) container bindings.
     *
     * Two bindings the donor forgot: point spatie's `activity_model` at the
     * core's Activity (so its custom columns are honoured by the package's own
     * helpers and the LogsActivity trait), and resolve the geo contract from the
     * configured implementation (so a host can swap providers).
     */
    protected function registerActivityLog(): void
    {
        // Spatie's own provider normally merges its config; in a bare package
        // test harness it may not have, so guarantee the keys spatie reads exist
        // (CauserResolver needs default_auth_driver). Existing values are kept —
        // only missing keys are filled — then activity_model points at the core
        // model so spatie writes the core's custom columns.
        config(array_merge([
            'activitylog.enabled' => config('activitylog.enabled', true),
            'activitylog.default_log_name' => config('activitylog.default_log_name', 'default'),
            'activitylog.default_auth_driver' => config('activitylog.default_auth_driver'),
            'activitylog.subject_returns_soft_deleted_models' => config('activitylog.subject_returns_soft_deleted_models', false),
            'activitylog.table_name' => config('activitylog.table_name', 'activity_log'),
            'activitylog.database_connection' => config('activitylog.database_connection'),
        ], [
            'activitylog.activity_model' => ActivityModel::class,
            // Make `retention_days` the single source of truth: spatie's
            // `activitylog:clean` reads `delete_records_older_than_days`, so map
            // our config key onto it (otherwise the documented key is inert).
            'activitylog.delete_records_older_than_days' => config('larafoundry-activitylog.retention_days', 365),
        ]));

        $this->app->bind(GeoResolver::class, function ($app) {
            $resolver = config('larafoundry-activitylog.geo.resolver', IpApiGeoResolver::class);

            return $app->make($resolver);
        });
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
        $this->loadRoutesFrom(__DIR__.'/../routes/profile.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/pin.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/qr.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/notifications.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/tickets.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'larafoundry');

        $this->registerAuthMiddleware();
        $this->registerAuthEventListeners();
        $this->localizeAuthMail();
        $this->registerTenancy();
        $this->registerAuthorization();
        $this->bootActivityLog();
        $this->bootAdmin();
        $this->bootMedia();
        $this->bootTickets();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
                SyncPermissionsCommand::class,
                PruneSignInRequestsCommand::class,
                PruneNotificationsCommand::class,
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
     * Boot the activity log (phase 2.1): admin routes behind the super-admin
     * gate, the route-access middleware (opt-in), the event-registry subscriber
     * and the viewing policy.
     *
     * The first admin surface of the platform: `larafoundry.admin` gates the
     * operator console; the activity log is its first section.
     */
    protected function bootActivityLog(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('larafoundry.admin', EnsureSuperAdmin::class);
        $router->aliasMiddleware('larafoundry.admin.otp', EnsureAdminOtpVerified::class);
        $router->aliasMiddleware('larafoundry.activity.route', LogActivity::class);

        $this->loadRoutesFrom(__DIR__.'/../routes/activitylog.php');

        Event::subscribe(LogRegisteredEvents::class);

        Gate::policy(ActivityModel::class, ActivityLogPolicy::class);
    }

    /**
     * Boot the operator console (phase 2.3): the admin user-management and
     * impersonation routes.
     *
     * Most routes sit behind the same `larafoundry.admin` gate as the activity
     * log; `impersonate.leave` is intentionally outside it (while impersonating
     * the actor is the target, not an admin — see routes/admin.php). The
     * impersonation policy is a plain injectable class the controller calls
     * directly, so there is nothing to register in the Gate here.
     */
    protected function bootAdmin(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');
    }

    /**
     * Boot the file/media library (phase 2.4): the signed private-file route.
     *
     * The route is the auth-gated, signed door to private-disk files.
     * FileStorageManager::temporaryUrl mints a short-lived signed URL to it for
     * disks that cannot presign (the local/private disk), so a private file is
     * never reachable by a raw, permanent path (recon finding #7). S3 presigns
     * natively. The manager owns that decision, so it does not depend on a
     * boot-time disk callback (which a test's Storage::fake would replace).
     */
    protected function bootMedia(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/media.php');
    }

    /**
     * Boot the helpdesk (phase 4.2): the ownership policy for the user-facing
     * ticket routes.
     *
     * The user routes load in boot() (every authenticated user has support); the
     * operator's admin-ticket routes live in routes/admin.php behind the
     * `larafoundry.admin` gate (loaded by bootAdmin), so the policy here only
     * guards the customer side. The new-ticket / reply lifecycle events
     * (TicketCreated / TicketReplied) are audited through the activity-log event
     * registry (config), not wired here.
     */
    protected function bootTickets(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);
    }

    /**
     * Register the core's auth middleware aliases.
     *
     * `larafoundry.account.active` enforces blocked/deleted gating;
     * `larafoundry.session.track` records and refreshes the tracked session row
     * each request; `larafoundry.confine_admin` keeps the platform super-admin
     * inside the operator console (phase 1.4). The host applies all three to its
     * authenticated/web route group.
     */
    protected function registerAuthMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('larafoundry.account.active', EnsureAccountIsActive::class);
        $router->aliasMiddleware('larafoundry.session.track', TrackSessionActivity::class);
        $router->aliasMiddleware('larafoundry.confine_admin', RedirectSuperAdminToConsole::class);
        $router->aliasMiddleware('larafoundry.pin', CheckPinLock::class);
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

        // The OTP step-up is proven once per session: any fresh login or a
        // logout drops the flag, so the operator must re-clear the gate. This
        // also closes the OAuth channel — an OAuth login never sets the flag, so
        // the gate always challenges before the console opens (phase 1.4).
        $forgetOtp = fn () => session()->forget(EnsureAdminOtpVerified::SESSION_KEY);
        Event::listen(Login::class, $forgetOtp);
        Event::listen(Logout::class, $forgetOtp);
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
            __DIR__.'/../config/larafoundry-activitylog.php' => config_path('larafoundry-activitylog.php'),
        ], 'larafoundry-activitylog');

        $this->publishes([
            __DIR__.'/../config/larafoundry-media.php' => config_path('larafoundry-media.php'),
        ], 'larafoundry-media');

        $this->publishes([
            __DIR__.'/../config/larafoundry-notifications.php' => config_path('larafoundry-notifications.php'),
        ], 'larafoundry-notifications-config');

        $this->publishes([
            __DIR__.'/../config/larafoundry-tickets.php' => config_path('larafoundry-tickets.php'),
        ], 'larafoundry-tickets-config');

        $this->publishes([
            __DIR__.'/../resources/js/Pages' => resource_path('js/Pages'),
        ], 'larafoundry-pages');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/larafoundry'),
        ], 'larafoundry-lang');
    }
}
