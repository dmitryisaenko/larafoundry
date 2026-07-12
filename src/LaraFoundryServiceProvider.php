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
use Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\CheckPinLock;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\EnsureAccountIsActive;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\TrackSessionActivity;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\AlertOnAdminOtpFailure;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\LogFailedLoginAttempt;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\SendAdminAccessAlertMail;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\SendWelcomeNotification;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\SyncCookieConsentOnLogin;
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
use Dmitryisaenko\LaraFoundry\Email\Console\Commands\PreviewEmailTemplatesCommand;
use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Policies\EmailTemplatePolicy;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureAdminOtpVerified;
use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureSuperAdmin;
use Dmitryisaenko\LaraFoundry\Http\Middleware\RedirectSuperAdminToConsole;
use Dmitryisaenko\LaraFoundry\Legal\Http\Middleware\EnsureTermsAccepted;
use Dmitryisaenko\LaraFoundry\Legal\Models\LegalPage;
use Dmitryisaenko\LaraFoundry\Legal\Policies\LegalPagePolicy;
use Dmitryisaenko\LaraFoundry\Legal\Support\ConsentManager;
use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
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
use Dmitryisaenko\LaraFoundry\Notifications\Providers\NotificationsUserDataExporter;
use Dmitryisaenko\LaraFoundry\Notifications\Providers\NotificationsUserDataPurger;
use Dmitryisaenko\LaraFoundry\Notifications\Support\NotificationService;
use Dmitryisaenko\LaraFoundry\Profile\Console\Commands\PurgeDeletedAccountsCommand;
use Dmitryisaenko\LaraFoundry\Profile\Providers\ConsentExporter;
use Dmitryisaenko\LaraFoundry\Profile\Providers\CoreUserProfileExporter;
use Dmitryisaenko\LaraFoundry\Profile\Providers\CoreUserProfilePurger;
use Dmitryisaenko\LaraFoundry\Profile\Providers\UiSettingsExporter;
use Dmitryisaenko\LaraFoundry\Profile\Providers\UserSessionsExporter;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataExportRegistry;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataPurgeRegistry;
use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyArchived;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyInvitationSent;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalCancelled;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalRejected;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalRequested;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRoleChanged;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationAccepted;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationRejected;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationResent;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationWithdrawn;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware\EnsureActiveTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware\SetActiveTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendCompanyArchivedNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendCompanyCreatedNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendEmployeeRemovalCancelledNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendEmployeeRemovalRejectedNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendEmployeeRemovalRequestedNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendEmployeeRemovedNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendEmployeeRoleChangedNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendInvitationAcceptedNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendInvitationRejectedNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendInvitationResentNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendInvitationSentNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\SendInvitationWithdrawnNotifications;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\PersonalTenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\SessionTenantResolver;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Dmitryisaenko\LaraFoundry\Tickets\Policies\TicketPolicy;
use Dmitryisaenko\LaraFoundry\Tickets\Providers\TicketsUserDataExporter;
use Dmitryisaenko\LaraFoundry\Tickets\Providers\TicketsUserDataPurger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

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
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry-email.php', 'larafoundry-email');
        $this->mergeConfigFrom(__DIR__.'/../config/larafoundry-legal.php', 'larafoundry-legal');

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
        $this->registerSettings();
        $this->registerEmail();
        $this->registerLegal();
    }

    /**
     * Wire the legal-page editor (phase 5.3): the legal-page repository.
     *
     * A singleton mirroring {@see registerEmail()} so the admin editor, the public
     * controller and the (phase 5.3/B) terms re-accept gate share one config-driven
     * store. The registry of editable pages lives in
     * `config('larafoundry-legal.pages')`; the database holds only the super-admin's
     * published pages. The viewing/editing policy is registered in {@see bootLegal()}.
     */
    protected function registerLegal(): void
    {
        $this->app->singleton(LegalPageRepository::class);

        // The consent authority (phase 5.3 part B): terms enforcement + cookie
        // consent, shared by the registration hook, the re-accept gate, the
        // consent controller, the login listener and the Inertia share.
        $this->app->singleton(ConsentManager::class);
    }

    /**
     * Wire the email-template editor (phase 5.1): the template repository.
     *
     * A singleton so the editor, the renderer and every future Notification that
     * looks a template up share one config-driven store (a host can rebind a
     * richer one without touching call sites). The registry of editable templates
     * lives in `config('larafoundry-email.templates')`; the database holds only a
     * super-admin's overrides. The viewing/editing policy is registered in
     * {@see bootEmail()}.
     */
    protected function registerEmail(): void
    {
        $this->app->singleton(EmailTemplateRepository::class);
    }

    /**
     * Wire the settings module (phase 5.1): the generic key-value repository.
     *
     * A singleton so the {@see Settings}
     * facade and every caller share one instance (and a host can rebind a richer
     * store without touching call sites). The store itself is config-driven — the
     * registry of declared keys lives in `config('larafoundry.settings')`.
     */
    protected function registerSettings(): void
    {
        $this->app->singleton(SettingsRepository::class);
    }

    /**
     * Wire the profile module (phase 5.1 seam, phase 5.3 export): the user-data
     * export registry.
     *
     * Mirrors {@see registerNavigation()} / {@see registerDashboard()} — a
     * singleton registry to which a host or module adds more by resolving it and
     * calling addProvider. Seeded here with the core sections the user owns
     * directly: identity, tracked sessions, UI preferences and consent state. The
     * helpdesk and notification modules add their own sections from
     * {@see bootTickets()} / {@see bootNotifications()}; the
     * {@see DataExportController} consumes {@see UserDataExportRegistry::collect()}
     * to stream the JSON download (phase 5.3, decision D-5.3-4).
     */
    protected function registerProfile(): void
    {
        $this->app->singleton(UserDataExportRegistry::class, function ($app) {
            return (new UserDataExportRegistry)
                ->addProvider($app->make(CoreUserProfileExporter::class))
                ->addProvider($app->make(UserSessionsExporter::class))
                ->addProvider($app->make(UiSettingsExporter::class))
                ->addProvider($app->make(ConsentExporter::class));
        });

        // The symmetric erasure registry (phase 5.3): seeded with the core purger
        // that anonymises the identity; the helpdesk and notification modules add
        // their own purgers from bootTickets() / bootNotifications(), exactly as on
        // the export side. The PurgeDeletedAccountsCommand runs it past the grace
        // period (decision D-5.3-3).
        $this->app->singleton(UserDataPurgeRegistry::class, function ($app) {
            return (new UserDataPurgeRegistry)
                ->addProvider($app->make(CoreUserProfilePurger::class));
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
        $this->loadRoutesFrom(__DIR__.'/../routes/settings.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/pin.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/qr.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/notifications.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/tickets.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/legal.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/consent.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'larafoundry');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'larafoundry');

        $this->registerAuthMiddleware();
        $this->registerAuthEventListeners();
        $this->bootOAuthCommunityDrivers();
        $this->localizeAuthMail();
        $this->registerTenancy();
        $this->registerAuthorization();
        $this->bootActivityLog();
        $this->bootAdmin();
        $this->bootMedia();
        $this->bootTickets();
        $this->bootNotifications();
        $this->bootEmail();
        $this->bootLegal();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
                SyncPermissionsCommand::class,
                PruneSignInRequestsCommand::class,
                PruneNotificationsCommand::class,
                PurgeDeletedAccountsCommand::class,
                PreviewEmailTemplatesCommand::class,
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
     *
     * The helpdesk also contributes the user's own tickets to the personal-data
     * export by adding its provider to the registry (phase 5.3) — the additive
     * idiom a module/host uses to own its export section.
     */
    protected function bootTickets(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);

        $this->app->make(UserDataExportRegistry::class)
            ->addProvider($this->app->make(TicketsUserDataExporter::class));

        $this->app->make(UserDataPurgeRegistry::class)
            ->addProvider($this->app->make(TicketsUserDataPurger::class));
    }

    /**
     * Boot the notification centre (phase 4.1): register its personal-data
     * exporter (phase 5.3).
     *
     * The notification routes/console wiring lives elsewhere; this hook adds the
     * module's section to the user-data export registry — the user's own inbox —
     * the same additive idiom the helpdesk uses, keeping the export flow ignorant
     * of every section.
     */
    protected function bootNotifications(): void
    {
        $this->app->make(UserDataExportRegistry::class)
            ->addProvider($this->app->make(NotificationsUserDataExporter::class));

        $this->app->make(UserDataPurgeRegistry::class)
            ->addProvider($this->app->make(NotificationsUserDataPurger::class));

        $this->registerLifecycleNotifications();
    }

    /**
     * Wire the owner-employee lifecycle notifications (phase 2a): one thin listener
     * per tenancy event pushes the in-app + email channels the Part-6 matrix marks.
     *
     * Gated by the `larafoundry-notifications.lifecycle.enabled` master switch so a
     * host can silence the whole set (the events still fire and still feed the
     * activity log). Listeners are resolved from the container, so their
     * NotificationService dependency is injected. Registration mirrors the RBAC
     * Event::listen wiring in registerAuthorization().
     */
    protected function registerLifecycleNotifications(): void
    {
        if (! config('larafoundry-notifications.lifecycle.enabled', true)) {
            return;
        }

        Event::listen(CompanyInvitationSent::class, SendInvitationSentNotifications::class);
        Event::listen(InvitationAccepted::class, SendInvitationAcceptedNotifications::class);
        Event::listen(InvitationRejected::class, SendInvitationRejectedNotifications::class);
        Event::listen(EmployeeRemoved::class, SendEmployeeRemovedNotifications::class);
        Event::listen(EmployeeRemovalRequested::class, SendEmployeeRemovalRequestedNotifications::class);
        Event::listen(EmployeeRemovalCancelled::class, SendEmployeeRemovalCancelledNotifications::class);
        Event::listen(EmployeeRemovalRejected::class, SendEmployeeRemovalRejectedNotifications::class);
        Event::listen(InvitationWithdrawn::class, SendInvitationWithdrawnNotifications::class);
        Event::listen(InvitationResent::class, SendInvitationResentNotifications::class);
        Event::listen(EmployeeRoleChanged::class, SendEmployeeRoleChangedNotifications::class);
        Event::listen(CompanyCreated::class, SendCompanyCreatedNotifications::class);
        Event::listen(CompanyArchived::class, SendCompanyArchivedNotifications::class);
    }

    /**
     * Boot the email-template editor (phase 5.1): its super-admin policy.
     *
     * The editor routes live in routes/admin.php behind the `larafoundry.admin`
     * gate (loaded by bootAdmin); the policy here is the explicit, identity-level
     * re-check the controller and form requests authorize against — defence in
     * depth over the zone gate (decision D-5.1-3), since the body is HTML rendered
     * into outgoing mail.
     */
    protected function bootEmail(): void
    {
        Gate::policy(EmailTemplate::class, EmailTemplatePolicy::class);
    }

    /**
     * Boot the legal-page editor (phase 5.3): its super-admin policy.
     *
     * The editor routes live in routes/admin.php behind the `larafoundry.admin`
     * gate (loaded by bootAdmin); the public pages load in boot() (routes/legal.php,
     * open to everyone). The policy here is the explicit, identity-level re-check the
     * editor controller and form requests authorize against — defence in depth over
     * the zone gate (mirrors decision D-5.1-3), since the body is HTML rendered into
     * a public page.
     */
    protected function bootLegal(): void
    {
        Gate::policy(LegalPage::class, LegalPagePolicy::class);
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

        // The terms re-acceptance gate (phase 5.3 part B). The host applies it to
        // its authenticated web group; it is fail-open (no published Terms = no
        // enforcement), so adding it is always safe.
        $router->aliasMiddleware('larafoundry.terms', EnsureTermsAccepted::class);
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

        // Unified admin-access-failure alert: password/lockout (above), the
        // operator-console OTP (Fortify's 2FA-failed event, gated to the
        // super-admin) and the session PIN (raised in PinController) all funnel
        // into AdminAccessAttemptFailed. The core's neutral default channel is
        // mail (SendAdminAccessAlertMail); a host adds Telegram/Slack/etc. by
        // listening to the same event — no core change needed.
        Event::listen(TwoFactorAuthenticationFailed::class, AlertOnAdminOtpFailure::class);
        Event::listen(AdminAccessAttemptFailed::class, SendAdminAccessAlertMail::class);

        // The OTP step-up is proven once per session: any fresh login or a
        // logout drops the flag, so the operator must re-clear the gate. This
        // also closes the OAuth channel — an OAuth login never sets the flag, so
        // the gate always challenges before the console opens (phase 1.4).
        $forgetOtp = fn () => session()->forget(EnsureAdminOtpVerified::SESSION_KEY);
        Event::listen(Login::class, $forgetOtp);
        Event::listen(Logout::class, $forgetOtp);

        // Adopt a guest's cookie-consent choice into their account on first login
        // (phase 5.3 part B) — settings become the source of truth once signed in.
        Event::listen(Login::class, SyncCookieConsentOnLogin::class);

        // Welcome the user once their address is verified (phase 5.1) — on
        // Verified, not Registered, so it follows the verification mail.
        Event::listen(Verified::class, SendWelcomeNotification::class);
    }

    /**
     * Auto-register community OAuth drivers (socialiteproviders/* packages).
     *
     * Native Socialite drivers (google, facebook, github, …) need no wiring. The
     * community ones normally require each host to hang a SocialiteWasCalled
     * listener in its own provider. This closes that over: for any slug mapped in
     * `larafoundry.auth.oauth.community_drivers` that is ALSO enabled in
     * `providers` AND whose handler class is installed, the core registers the
     * listener — so a host just `composer require`s the package and lists the slug.
     *
     * The core keeps NO hard dependency on socialiteproviders/*: every reference
     * is a class_exists guard. With no package installed, SocialiteWasCalled does
     * not exist and this returns immediately; a slug mapped without its package is
     * a valid (if non-working) config and never throws — the button simply fails
     * gracefully at redirect time (OAuthController catches the missing driver).
     *
     * SECURITY: the handler FQCN is registered verbatim as an event listener, so
     * it is read ONLY from config (a trusted, static source) — never from the
     * request or any user input — preventing an attacker from coercing the core
     * into wiring an arbitrary class.
     */
    protected function bootOAuthCommunityDrivers(): void
    {
        // socialiteproviders/manager ships SocialiteWasCalled; if it is absent no
        // community package is installed at all, so there is nothing to wire.
        // class_exists() takes a leading backslash; Event::listen() does NOT
        // normalize it, and the dispatched event's get_class() has no leading
        // slash — so the listener key must be the bare FQCN or it would never fire.
        $eventClass = 'SocialiteProviders\Manager\SocialiteWasCalled';

        if (! class_exists($eventClass)) {
            return;
        }

        $active = (array) config('larafoundry.auth.oauth.providers', []);
        $map = (array) config('larafoundry.auth.oauth.community_drivers', []);

        foreach ($map as $slug => $handler) {
            // Only wire slugs the host has actually enabled.
            if (! in_array($slug, $active, true)) {
                continue;
            }

            // Slug enabled but its package is not installed: a valid configuration
            // (the button just will not work) — skip silently, never throw.
            if (! is_string($handler) || ! class_exists($handler)) {
                continue;
            }

            Event::listen($eventClass, [ltrim($handler, '\\'), 'handle']);
        }
    }

    /**
     * Route the core's auth mails through the editable email templates, with a
     * localised static fallback (phase 5.1, decisions D-5.1-8/12).
     *
     * Verify-email and password-reset render from their `email_verification` /
     * `password_reset` templates (a super-admin can rewrite the HTML); when a
     * template is switched off, `mailMessage()` returns null and each closure
     * falls back to a MailMessage built from the core's `larafoundry::auth`
     * strings. Fortify/Laravel still own sending and link generation. Decision
     * D3-loc still holds — the core owns the wording and ships it translated.
     *
     * The admin login-alert is intentionally NOT templated: it is an internal
     * security notice to the operator, not a customer-facing mail, so it stays a
     * plain translated MailMessage a super-admin cannot soften or break.
     */
    protected function localizeAuthMail(): void
    {
        VerifyEmail::toMailUsing(function (mixed $notifiable, string $url) {
            $templated = app(EmailTemplateRepository::class)->mailMessage(
                'email_verification',
                $notifiable->locale ?? null,
                ['name' => (string) ($notifiable->name ?? ''), 'verification_url' => $url],
            );

            if ($templated !== null) {
                return $templated;
            }

            return (new MailMessage)
                ->subject(__('larafoundry::auth.verify_email.subject'))
                ->line(__('larafoundry::auth.verify_email.intro'))
                ->action(__('larafoundry::auth.verify_email.action'), $url)
                ->line(__('larafoundry::auth.verify_email.outro'));
        });

        ResetPassword::toMailUsing(function (mixed $notifiable, string $token) {
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : ($notifiable->email ?? '');

            // Rebuild the reset URL the way Laravel's default notification does,
            // but never hard-depend on the route existing (package test context).
            $url = Route::has('password.reset')
                ? url(route('password.reset', ['token' => $token, 'email' => $email], false))
                : url('/reset-password/'.$token.'?email='.urlencode((string) $email));

            $templated = app(EmailTemplateRepository::class)->mailMessage(
                'password_reset',
                $notifiable->locale ?? null,
                ['name' => (string) ($notifiable->name ?? ''), 'reset_url' => $url],
            );

            if ($templated !== null) {
                return $templated;
            }

            return (new MailMessage)
                ->subject(__('larafoundry::auth.reset_password.subject'))
                ->line(__('larafoundry::auth.reset_password.intro'))
                ->action(__('larafoundry::auth.reset_password.action'), $url)
                ->line(__('larafoundry::auth.reset_password.outro'));
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
            __DIR__.'/../config/larafoundry-email.php' => config_path('larafoundry-email.php'),
        ], 'larafoundry-email-config');

        $this->publishes([
            __DIR__.'/../config/larafoundry-legal.php' => config_path('larafoundry-legal.php'),
        ], 'larafoundry-legal-config');

        $this->publishes([
            __DIR__.'/../resources/js/Pages' => resource_path('js/Pages'),
        ], 'larafoundry-pages');

        $this->publishes([
            __DIR__.'/../resources/views/mail' => resource_path('views/vendor/larafoundry/mail'),
        ], 'larafoundry-mail-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/larafoundry'),
        ], 'larafoundry-lang');
    }
}
