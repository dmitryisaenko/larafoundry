<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Billing\Cashier\Customer as PaddleCustomerModel;
use Dmitryisaenko\LaraFoundry\Billing\Cashier\Subscription as PaddleSubscriptionModel;
use Dmitryisaenko\LaraFoundry\Billing\Cashier\SubscriptionItem as PaddleSubscriptionItemModel;
use Dmitryisaenko\LaraFoundry\Billing\Cashier\Transaction as PaddleTransactionModel;
use Dmitryisaenko\LaraFoundry\Billing\Console\Commands\CheckExpiringSubscriptions;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Dashboard\BillingRevenueWidgetProvider;
use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Http\Middleware\CheckAccessMiddleware;
use Dmitryisaenko\LaraFoundry\Billing\Http\Middleware\EnsureEntitled;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\AccrueAffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\AttributeReferral;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\MirrorPaddleSubscriptionState;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\MirrorStripeSubscriptionState;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\NotifyOwnersOfPayment;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\RecordStripePayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Navigation\AffiliateAdminMenuProvider;
use Dmitryisaenko\LaraFoundry\Billing\Navigation\AffiliateUserMenuProvider;
use Dmitryisaenko\LaraFoundry\Billing\Navigation\BillingAdminMenuProvider;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;
use Dmitryisaenko\LaraFoundry\Credits\Listeners\HandlePaddleCreditNotification;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Dmitryisaenko\LaraFoundry\LaraFoundryServiceProvider;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated as CoreCompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier as StripeCashier;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Paddle\Cashier as PaddleCashier;
use Laravel\Paddle\Events\WebhookReceived as PaddleWebhookReceived;
use Laravel\Paddle\Http\Controllers\WebhookController as PaddleWebhookController;

/**
 * The billing module: real gateways (Cashier Stripe, Cashier Paddle MoR), plans,
 * promo codes, entitlements, access enforcement, the payment ledger and the
 * affiliate program, on top of the billing seam (phase 3.1).
 *
 * Until v0.45.0 this was the separate paid `larafoundry-billing` add-on; it now
 * ships inside the core and is registered by {@see LaraFoundryServiceProvider}.
 *
 * Everything hangs off ONE switch, `larafoundry.billing.enabled`:
 *  - off (default): the module is dormant. It merges its config and registers only
 *    what does nothing by itself: its two route middleware aliases (both
 *    short-circuit open), the promo ability, the promo rate limiter, the gateway
 *    drivers named in config (nothing calls them without the billing routes), the
 *    Company::payments relation (inert until queried) and its console command (which
 *    itself exits early). It loads NO route, menu item or dashboard widget, attaches
 *    no webhook or payment listener, and turns off the routes both Cashier packages
 *    would otherwise register.
 *  - on: the full wiring below. Cashier-Stripe's routes (`/stripe/*`) exist only with
 *    the Stripe gateway configured, the Paddle webhook (`/paddle/webhook`, declared
 *    by this provider) only with the Paddle gateway configured.
 *
 * The billing TABLES are created regardless of the switch (they live in the core's
 * own database/migrations): a table that exists or not depending on config is a trap
 * for any code that looks into it while billing is off. The contracts the seam ships
 * empty are switched per resolution in the core provider's registerBilling().
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/larafoundry-billing.php', 'larafoundry-billing');

        // Both Cashier packages mount their routes in boot() when this static flag is
        // set. Set in a booting callback: it runs after every register() and before
        // any boot(), so it reads the final config (a test harness sets it in between)
        // and lands before Cashier looks. Set both ways, so a process that boots
        // billing off and then on does not inherit the previous value.
        //
        // Cashier-Paddle's own routes stay off for good: it prefixes them with the
        // shared `cashier.path` key, which Cashier-Stripe (merged first) has already
        // set to `stripe`, so its controller would take over `/stripe/webhook` under
        // the same `cashier.webhook` name. registerPaddleWebhookRoute() declares the
        // Paddle webhook at its own path instead.
        $this->app->booting(function (): void {
            StripeCashier::$registersRoutes = $this->enabled()
                && (bool) config('larafoundry-billing.gateways.stripe');
            PaddleCashier::$registersRoutes = false;
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/larafoundry-billing.php' => config_path('larafoundry-billing.php'),
        ], 'larafoundry-billing-config');

        // The billing pages also ship under the core's `larafoundry-pages` tag (they
        // live in the core's resources/js/Pages); these narrower tags are kept so a
        // host that published only the billing screens can re-publish just those.
        $pages = __DIR__.'/../../resources/js/Pages';

        $this->publishes([
            $pages.'/Billing' => resource_path('js/Pages/Billing'),
        ], 'larafoundry-billing-pages');

        $this->publishes([
            $pages.'/Admin/Payments' => resource_path('js/Pages/Admin/Payments'),
            $pages.'/Admin/PromoCodes' => resource_path('js/Pages/Admin/PromoCodes'),
            $pages.'/Admin/Affiliates' => resource_path('js/Pages/Admin/Affiliates'),
            $pages.'/Admin/Payouts' => resource_path('js/Pages/Admin/Payouts'),
        ], 'larafoundry-billing-admin-pages');

        $this->publishes([
            $pages.'/Affiliate' => resource_path('js/Pages/Affiliate'),
        ], 'larafoundry-billing-affiliate-pages');

        $this->registerPromoGate();
        $this->registerPromoRateLimiter();
        $this->registerGatewayDrivers();
        $this->registerEntitlementMiddleware();
        $this->registerAccessMiddleware();
        $this->registerCompanyRelations();
        $this->registerCommands();

        if (! $this->enabled()) {
            return;
        }

        $this->bridgeWebhookSecrets();
        $this->registerPaddleModels();
        $this->registerStripeWebhookMirror();
        $this->registerPaddleWebhookMirror();
        $this->registerPaddleWebhookRoute();
        $this->registerPaymentEventListeners();
        $this->registerPromoRoutes();
        $this->registerWebRoutes();
        $this->registerAdminRoutes();
        $this->registerAdminMenu();
        $this->registerDashboardWidgets();
        $this->registerAffiliateRoutes();
        $this->registerAffiliateListeners();
        $this->registerAffiliateAdminRoutes();
        $this->registerAffiliateAdminMenu();
        $this->registerAffiliateDashboardRoutes();
        $this->registerAffiliateUserMenu();
    }

    /**
     * The module's master switch (the seam's own `larafoundry.billing.enabled`).
     */
    protected function enabled(): bool
    {
        return (bool) config('larafoundry.billing.enabled');
    }

    /**
     * Bridge the module's webhook signing secrets into the keys Cashier's own
     * webhook routes read, so a host that only sets the module key still gets a
     * signature-verified route.
     *
     * This closes a fail-open gap: each Cashier webhook controller attaches its
     * signature-verification middleware ONLY when its own config key is truthy
     * (Stripe reads the nested `cashier.webhook.secret`, Paddle the FLAT
     * `cashier.webhook_secret`). A host that configured only
     * `larafoundry-billing.{stripe,paddle}.webhook.secret` would otherwise expose an
     * unverified webhook route — and the mirror, whose guard sees the module key as
     * present, would happily mutate access columns from an unauthenticated body.
     * Copying the module key into Cashier's key (only when Cashier's is empty, so a
     * host that set Cashier directly still wins) keeps the route's verification and
     * the mirror's guard reading the same source of truth.
     *
     * Runs in boot() (before the mirror guards, which read the bridged key): boot
     * runs after every provider's register(), so a host that sets the module config
     * from its own provider is still seen — and Cashier's webhook controllers resolve
     * their secret per-request, after boot, so the bridge lands in time.
     */
    protected function bridgeWebhookSecrets(): void
    {
        $stripe = config('larafoundry-billing.stripe.webhook.secret');

        if (is_string($stripe) && $stripe !== '' && ! config('cashier.webhook.secret')) {
            config(['cashier.webhook.secret' => $stripe]);
        }

        $paddle = config('larafoundry-billing.paddle.webhook.secret');

        if (is_string($paddle) && $paddle !== '' && ! config('cashier.webhook_secret')) {
            config(['cashier.webhook_secret' => $paddle]);
        }
    }

    /**
     * Load the partner dashboard routes (Ф4.4), only when the program and the dashboard
     * are enabled. Wrapped in the configured prefix + middleware (web for the session flow,
     * auth — a partner must be signed in to see their own earnings).
     */
    protected function registerAffiliateDashboardRoutes(): void
    {
        if (! config('larafoundry-billing.affiliate.enabled')) {
            return;
        }

        if (! config('larafoundry-billing.affiliate.dashboard.enabled', true)) {
            return;
        }

        Route::middleware((array) config('larafoundry-billing.affiliate.dashboard.middleware', ['web', 'auth']))
            ->prefix((string) config('larafoundry-billing.affiliate.dashboard.prefix', 'affiliate'))
            ->group(__DIR__.'/../../routes/billing/affiliate-dashboard.php');
    }

    /**
     * Add the partner dashboard link to the regular user ('tenant') menu (Ф4.4), when the
     * program is enabled. The provider itself returns nothing in admin eligibility, so the
     * item only appears where every user is (or may become) a partner.
     */
    protected function registerAffiliateUserMenu(): void
    {
        if (! config('larafoundry-billing.affiliate.enabled')) {
            return;
        }

        $this->app->make(MenuBuilder::class)->addProvider(new AffiliateUserMenuProvider);
    }

    /**
     * Load the super-admin affiliate/payout console routes (Ф4.4), only when the admin
     * console AND the affiliate program are on. The file carries the `larafoundry.admin`
     * gate group itself.
     */
    protected function registerAffiliateAdminRoutes(): void
    {
        if (! $this->affiliateConsoleEnabled()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/billing/affiliate-admin.php');
    }

    /**
     * Add the affiliate console's two menu items onto the core menu builder (Ф4.4), the
     * same additive idiom as {@see registerAdminMenu()} and under the same gate.
     */
    protected function registerAffiliateAdminMenu(): void
    {
        if (! $this->affiliateConsoleEnabled()) {
            return;
        }

        $this->app->make(MenuBuilder::class)->addProvider(new AffiliateAdminMenuProvider);
    }

    /**
     * Whether the affiliate super-admin console should be wired: the admin console on
     * and the affiliate program on (billing itself is checked once in boot()).
     */
    protected function affiliateConsoleEnabled(): bool
    {
        return (bool) config('larafoundry-billing.admin.enabled', true)
            && (bool) config('larafoundry-billing.affiliate.enabled');
    }

    /**
     * Load the affiliate program's public routes — the referral-capture endpoint
     * (Ф4.4) — only when the program AND the capture route are enabled.
     *
     * Wrapped in the configured prefix + middleware (`web` for cookies, plus the
     * route-level throttle so the endpoint can never be a code-enumeration oracle). A
     * host that surfaces its own referral link handling turns the capture route off.
     */
    protected function registerAffiliateRoutes(): void
    {
        if (! config('larafoundry-billing.affiliate.enabled')) {
            return;
        }

        if (! config('larafoundry-billing.affiliate.attribution.capture_route.enabled', true)) {
            return;
        }

        $middleware = (array) config('larafoundry-billing.affiliate.attribution.capture_route.middleware', ['web']);
        $throttle = config('larafoundry-billing.affiliate.attribution.capture_route.throttle');

        if (is_string($throttle) && $throttle !== '') {
            $middleware[] = 'throttle:'.$throttle;
        }

        Route::middleware($middleware)
            ->prefix((string) config('larafoundry-billing.affiliate.attribution.capture_route.prefix', 'r'))
            ->group(__DIR__.'/../../routes/billing/affiliate.php');
    }

    /**
     * Wire the affiliate program's event hooks (Ф4.4), each onto a seam that already
     * fires — so the program needs no change to the host:
     *  - {@see AttributeReferral} on the core's {@see CoreCompanyCreated} locks
     *    attribution in `teams` mode, and on {@see Registered} in `personal` mode,
     *    where the new USER is the tenant and no company is ever created;
     *  - {@see AccrueAffiliateCommission} on {@see CompanyPaymentProcessed}
     *    accrues / claws back a commission.
     *
     * Both attribution hooks are attached unconditionally and each returns immediately
     * in the other mode, so a host that flips `larafoundry.tenancy.mode` after boot (or
     * a test that does) still attributes exactly once — the mode check lives with the
     * behaviour, not with the wiring.
     *
     * Registered only when the program is enabled. Every listener fails closed on every
     * guard.
     */
    protected function registerAffiliateListeners(): void
    {
        if (! config('larafoundry-billing.affiliate.enabled')) {
            return;
        }

        Event::listen(CoreCompanyCreated::class, AttributeReferral::class);
        Event::listen(Registered::class, [AttributeReferral::class, 'handleRegistration']);
        Event::listen(CompanyPaymentProcessed::class, AccrueAffiliateCommission::class);
    }

    /**
     * Register the module's console commands (sub-phase B7a).
     *
     * Only in the console, so the classes are never resolved on a web request. The
     * expiry-reminder cron is registered but NOT scheduled here — a host adds
     * `->daily()` to its own schedule, keeping cadence the host's call. The command
     * itself short-circuits when billing is off, so registering it unconditionally is
     * inert until a host both enables billing and schedules it.
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            CheckExpiringSubscriptions::class,
        ]);
    }

    /**
     * Give the core Company a `payments` relation WITHOUT editing the Company model
     * (sub-phase B7a).
     *
     * `resolveRelationUsing` registers it on the core Company class at boot;
     * Eloquent's relation resolver walks up the class hierarchy, so the host's Billable
     * Company subclass inherits it too. Registered unconditionally — it is inert until
     * something queries `$company->payments`, and keeping it billing-flag-free means a
     * host can read payment history even while enforcement is off.
     */
    protected function registerCompanyRelations(): void
    {
        Company::resolveRelationUsing(
            'payments',
            fn (Company $company): HasMany => $company->hasMany(CompanyPayment::class, 'company_id'),
        );
    }

    /**
     * Wire the B7a payment-event listeners.
     *
     * Two listeners with different lifetimes:
     *  - {@see NotifyOwnersOfPayment} on {@see CompanyPaymentProcessed} ALWAYS — it is
     *    inert when `notifications.enabled` is false, so a host can listen to the event
     *    itself. Kept off the webhook guard so notifying works regardless of how a
     *    payment was recorded.
     *  - {@see RecordStripePayment} on Cashier's {@see WebhookReceived} only with the
     *    Stripe driver configured AND a signing secret present — the same fail-closed
     *    guard as the mirror (Cashier verifies the signature upstream before firing).
     *
     * The recorder and the mirror both listen to WebhookReceived but never overlap:
     * the mirror handles `customer.subscription.*`, the recorder handles `invoice.*`.
     */
    protected function registerPaymentEventListeners(): void
    {
        Event::listen(CompanyPaymentProcessed::class, NotifyOwnersOfPayment::class);

        if (! config('larafoundry-billing.gateways.stripe')) {
            return;
        }

        $secret = config('larafoundry-billing.stripe.webhook.secret') ?: config('cashier.webhook.secret');

        if (! is_string($secret) || $secret === '') {
            return;
        }

        Event::listen(WebhookReceived::class, RecordStripePayment::class);
    }

    /**
     * Register the named rate limiter that guards the promo-validate endpoint
     * (sub-phase B5).
     *
     * Registered unconditionally (it is inert until the route below references it,
     * and a host that loads that route itself still finds it). Keyed by the authenticated
     * user when present, else the client IP — a logged-in caller is throttled per
     * account, an anonymous one per address. The limit comes from config so a host
     * tunes it without editing the package; the donor's equivalent endpoint had NO
     * throttle, which made it a code-enumeration oracle.
     */
    protected function registerPromoRateLimiter(): void
    {
        $throttle = config('larafoundry-billing.promo.validate_route.throttle', []);

        $max = is_array($throttle) && is_int($throttle['max'] ?? null) && $throttle['max'] > 0
            ? $throttle['max']
            : 5;

        $decay = is_array($throttle) && is_int($throttle['decay'] ?? null) && $throttle['decay'] > 0
            ? $throttle['decay']
            : 60;

        RateLimiter::for('larafoundry-billing-promo', function (Request $request) use ($max, $decay) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinutes((int) ceil($decay / 60), $max)->by((string) $key);
        });
    }

    /**
     * Load the promo routes, but only when the host has opted the public validate
     * endpoint in (sub-phase B5).
     *
     * A host that does not surface a promo-check endpoint ships no route at all. The
     * route file reads its prefix/middleware from config and always appends the
     * named limiter above, so the endpoint cannot be exposed unthrottled.
     */
    protected function registerPromoRoutes(): void
    {
        if (! config('larafoundry-billing.promo.validate_route.enabled')) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/billing/api.php');
    }

    /**
     * Load the owner billing web routes (pricing/checkout/history/blocked) unless the
     * host has opted out (sub-phase B7a).
     *
     * Wrapped in the configured prefix + middleware group (`web` for the Inertia
     * session/CSRF flow, `auth`). The route NAMES are `billing.*` regardless of the URL
     * prefix, so the access middleware's exemptions and the blocked redirect reference
     * them stably. A host that prefers to declare these routes itself sets
     * `routes.web.enabled` false.
     */
    protected function registerWebRoutes(): void
    {
        if (! config('larafoundry-billing.routes.web.enabled', true)) {
            return;
        }

        Route::middleware((array) config('larafoundry-billing.routes.web.middleware', ['web', 'auth']))
            ->prefix((string) config('larafoundry-billing.routes.web.prefix', 'billing'))
            ->group(__DIR__.'/../../routes/billing/web.php');
    }

    /**
     * Define the promo-management ability the admin FormRequests gate on (sub-phase
     * B7b — closes B5 debt D-B5-4).
     *
     * The Store/Update promo FormRequests are fail-closed on
     * {@see PromoCode::MANAGE_ABILITY}; this maps that ability to the canonical
     * super-admin check, so the admin promo console authorises out of the box; a host
     * that wants a finer policy re-defines the gate. Registered unconditionally — it is
     * inert until the admin routes (loaded only with billing + admin on) reference it.
     */
    protected function registerPromoGate(): void
    {
        Gate::define(
            PromoCode::MANAGE_ABILITY,
            fn ($user): bool => $this->app->make(VisitorStatus::class)->isAdmin($user),
        );
    }

    /**
     * Load the super-admin console routes (payments + promo CRUD), only when the admin
     * console is enabled (sub-phase B7b).
     *
     * The route file carries the `larafoundry.admin` gate group itself (no double-gating
     * here). A host that turns the console off ships no admin route at all.
     */
    protected function registerAdminRoutes(): void
    {
        if (! config('larafoundry-billing.admin.enabled', true)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/billing/admin.php');
    }

    /**
     * Register the console's two menu items onto the core menu builder (sub-phase
     * B7b), the same additive idiom a host uses.
     *
     * Resolving the {@see MenuBuilder} singleton here appends the provider to the one
     * shared instance; the menu is built later at request time, so the provider is
     * present then. Same guard as the routes so the items never appear without their
     * screens.
     */
    protected function registerAdminMenu(): void
    {
        if (! config('larafoundry-billing.admin.enabled', true)) {
            return;
        }

        $this->app->make(MenuBuilder::class)->addProvider(new BillingAdminMenuProvider);
    }

    /**
     * Register the revenue widget onto the core operator dashboard (B9), the exact
     * mirror of {@see registerAdminMenu()}.
     *
     * Resolving the core {@see DashboardBuilder} singleton here appends the provider to
     * the one shared instance; the dashboard is built later at request time, so the
     * provider is present then. Same gate as the admin menu/routes so the revenue
     * widget never appears without the console it belongs to.
     */
    protected function registerDashboardWidgets(): void
    {
        if (! config('larafoundry-billing.admin.enabled', true)) {
            return;
        }

        $this->app->make(DashboardBuilder::class)->addProvider($this->app->make(BillingRevenueWidgetProvider::class));
    }

    /**
     * Alias the entitlement middleware so a host gates a route with
     * `->middleware('entitlement:<feature>')` (sub-phase B6).
     *
     * Mirrors how the core aliases its own middleware (`larafoundry.tenant.required`
     * et al.). No config toggle: with billing off the middleware itself short-circuits
     * open, so aliasing it unconditionally is inert until a host actually attaches it
     * to a route — and a host route that carries it keeps resolving when billing is off.
     */
    protected function registerEntitlementMiddleware(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('entitlement', EnsureEntitled::class);
    }

    /**
     * Alias the access-enforcement middleware so a host gates its tenant routes with
     * `->middleware('billing.access')` (sub-phase B7a).
     *
     * Like the entitlement alias, registered unconditionally: with billing off the
     * middleware short-circuits open, so aliasing it is inert until a host attaches it.
     * It is meant to sit AFTER the core's `larafoundry.account.active` /
     * `larafoundry.tenant.required` in a host's tenant group (it covers only the
     * subscription axis, never the core's user-ban / super-admin-block checks).
     */
    protected function registerAccessMiddleware(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('billing.access', CheckAccessMiddleware::class);
    }

    /**
     * Register every configured gateway driver against the core's manager via its
     * extend() seam, so call sites resolve a real driver through one config value
     * and never learn which provider answered.
     *
     * Runs in boot() (not register()): by boot time the core provider has bound
     * the manager singleton, and the extend() calls land on the one shared manager
     * instance.
     */
    protected function registerGatewayDrivers(): void
    {
        $manager = $this->app->make(PaymentGatewayManager::class);

        foreach ((array) config('larafoundry-billing.gateways', []) as $name => $driver) {
            if (! is_string($name) || $name === '' || ! is_string($driver) || $driver === '') {
                continue;
            }

            $manager->extend(
                $name,
                fn (Container $container): PaymentGatewayInterface => $container->make($driver),
            );
        }
    }

    /**
     * Mirror Stripe's verified webhook state into the billable's columns, but only
     * when the Stripe driver is configured AND a webhook signing secret is present.
     *
     * The real incoming webhook is Cashier's own route, and Cashier attaches its
     * signature-verification middleware ONLY when cashier.webhook.secret is set —
     * so without a signing secret it processes (and fires WebhookReceived on)
     * unauthenticated request bodies. Since this listener writes access-granting
     * columns, registering it without a signing secret would expose an
     * unauthenticated access-mutation path. Fail closed: no signing secret => do
     * not mirror at all. (STRIPE_WEBHOOK_SECRET is therefore mandatory to enable
     * the mirror.)
     */
    protected function registerStripeWebhookMirror(): void
    {
        if (! config('larafoundry-billing.gateways.stripe')) {
            return;
        }

        $webhookSecret = config('larafoundry-billing.stripe.webhook.secret')
            ?: config('cashier.webhook.secret');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return;
        }

        Event::listen(WebhookReceived::class, MirrorStripeSubscriptionState::class);
    }

    /**
     * Point Cashier-Paddle at the module's own `paddle_`-prefixed tables — only when
     * the Paddle gateway is configured.
     *
     * Cashier-Stripe and Cashier-Paddle each create `subscriptions` /
     * `subscription_items` (and Paddle a generic `customers`) with different
     * schemas. A host running BOTH gateways (the multi-gateway-by-region routing B2
     * was built for) would have the two packages collide on those names. The module
     * owns Paddle's tables under a `paddle_` prefix via the {@see PaddleCustomerModel}
     * et al. model overrides, registered here so Cashier-Paddle's own controllers and
     * relations read/write the prefixed tables. The tables themselves are created by
     * the core's migrations for every host.
     *
     * Runs before the webhook mirror so the model overrides are in place before any
     * Paddle webhook is handled.
     */
    protected function registerPaddleModels(): void
    {
        if (! config('larafoundry-billing.gateways.paddle')) {
            return;
        }

        PaddleCashier::useCustomerModel(PaddleCustomerModel::class);
        PaddleCashier::useSubscriptionModel(PaddleSubscriptionModel::class);
        PaddleCashier::useSubscriptionItemModel(PaddleSubscriptionItemModel::class);
        PaddleCashier::useTransactionModel(PaddleTransactionModel::class);
    }

    /**
     * Mirror Paddle's verified webhook state into the billable's columns, with the
     * same fail-closed guard as the Stripe mirror: register the listener only when
     * the Paddle driver is configured AND a webhook signing secret is present.
     *
     * Cashier-Paddle attaches its signature-verification middleware to its webhook
     * route ONLY when a webhook secret is set; without one it would fire
     * {@see PaddleWebhookReceived} on unauthenticated bodies. Since this listener
     * writes access-granting columns, registering it secret-less would open an
     * unauthenticated access-mutation path — so no signing secret means no mirror.
     * NOTE the secret lives under Cashier-Paddle's FLAT `cashier.webhook_secret`
     * (not the nested `cashier.webhook.secret` Stripe Cashier uses).
     *
     * The Stripe and Paddle mirrors listen to DIFFERENT event classes
     * ({@see WebhookReceived} vs {@see PaddleWebhookReceived}), so the two never
     * cross-fire.
     */
    protected function registerPaddleWebhookMirror(): void
    {
        if (! config('larafoundry-billing.gateways.paddle')) {
            return;
        }

        $webhookSecret = config('larafoundry-billing.paddle.webhook.secret')
            ?: config('cashier.webhook_secret');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return;
        }

        Event::listen(PaddleWebhookReceived::class, MirrorPaddleSubscriptionState::class);

        // Credit purchases ride the same verified route (v0.47.0); a host with its
        // own notification address calls PaddleCreditPurchases::handle() instead.
        Event::listen(PaddleWebhookReceived::class, HandlePaddleCreditNotification::class);
    }

    /**
     * Declare Cashier-Paddle's webhook route at `/paddle/webhook` — only when the
     * Paddle gateway is configured.
     *
     * Cashier-Paddle's own route registration is switched off in register(): it
     * prefixes the route with the shared `cashier.path`, which Cashier-Stripe owns,
     * and names it `cashier.webhook` like Stripe's. The controller is Cashier-Paddle's
     * own, so its signature-verification middleware (attached when
     * `cashier.webhook_secret` is set) and the events it fires are unchanged.
     */
    protected function registerPaddleWebhookRoute(): void
    {
        if (! config('larafoundry-billing.gateways.paddle')) {
            return;
        }

        Route::post('paddle/webhook', PaddleWebhookController::class)
            ->name('larafoundry-billing.paddle.webhook');
    }
}
