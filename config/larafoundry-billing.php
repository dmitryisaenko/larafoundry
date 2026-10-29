<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;

/*
|--------------------------------------------------------------------------
| LaraFoundry billing module configuration
|--------------------------------------------------------------------------
| The billing module (until v0.45.0 the separate `larafoundry-billing`
| add-on) keeps its settings here, apart from `config/larafoundry.php`, which
| holds the master switch `larafoundry.billing.enabled` and `gateway.default`.
| Nothing in this file takes effect until the host sets
| LARAFOUNDRY_BILLING_ENABLED=true: with billing off the module loads no route,
| menu item, widget, gateway or webhook listener. Its tables are created either
| way.
|
| The "B2..B7" markers name the sub-phase that introduced each block.
*/

return [

    /*
    | The model that carries the gateway's billing identity (the Cashier
    | Billable, added in B3). The add-on never mixes a Billable trait into the
    | core Company (it does not own that class); instead the host applies the
    | add-on's BillingCustomer trait to its OWN Company subclass and points this
    | at it (build node 1, memory `billing-addon-decisions`). The core Company
    | stays gateway-agnostic. Default is the core Company so a host without a
    | subclass still resolves something.
    |
    | In `personal` tenancy mode there are no companies: the payer is the person, so
    | this points at the host's User class (with the BillingCustomer + BillingSubscriber
    | traits). The add-on's migrations follow this value — the Cashier and subscription
    | columns land on the table of whatever model is named here.
    */
    'billable_model' => env('LARAFOUNDRY_BILLING_BILLABLE_MODEL', Company::class),

    /*
    | Gateway driver classes, keyed by the name used in
    | `larafoundry.billing.gateway.default` and in region routing. The provider
    | registers each against the core PaymentGatewayManager via extend(). Empty
    | until B3 (Stripe) and B4 (Paddle) add their drivers; the wiring is proven
    | in B1 with a configured driver resolving through the manager.
    */
    'gateways' => [
        // 'stripe' => \Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver::class,  // B3
        // 'paddle' => \Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver::class,  // B4
    ],

    /*
    | The model that carries Paddle's billing identity (B4). Distinct from
    | `billable_model` above (the Stripe one) because the two Cashier packages
    | each ship a `Billable` trait with colliding method names — a single model
    | cannot use both, so a host that routes some tenants to Paddle declares a
    | SECOND Company subclass using the add-on's `BillingCustomerPaddle` trait and
    | points this at it (decision D-B4-1). Both subclasses share the `companies`
    | row; which one is used depends on the gateway the tenant's region routes to.
    | Default is the core Company so isConfigured() degrades to false rather than
    | fataling when a host has not wired Paddle.
    */
    'paddle_billable_model' => env('LARAFOUNDRY_BILLING_PADDLE_BILLABLE_MODEL', Company::class),

    /*
    | Stripe gateway settings (B3). All env-driven so the shipped config carries
    | no real keys. `secret`/`key` are the API credentials; the driver falls back
    | to Cashier's own `cashier.secret` when this is empty, so a host that
    | configured Cashier directly still works. `webhook.secret` is the signing
    | secret used to verify incoming events (the verification is non-negotiable —
    | see PaymentGatewayInterface::verifyWebhook). `checkout.*` are the redirect
    | targets for the hosted Stripe Checkout flow (decision D-B3-2): subscribe()
    | returns the session url and the host redirects the buyer there.
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'key' => env('STRIPE_KEY'),
        'webhook' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
        'checkout' => [
            'success_url' => env('STRIPE_CHECKOUT_SUCCESS_URL'),
            'cancel_url' => env('STRIPE_CHECKOUT_CANCEL_URL'),
        ],
    ],

    /*
    | Paddle gateway settings (B4). All env-driven so the shipped config carries no
    | real keys. Paddle is a Merchant-of-Record: it owns the VAT/invoicing the
    | contract deliberately leaves out, which is exactly why a host routes a
    | tax-complex region (e.g. UA) here. `api_key`/`client_side_token` are the
    | server and browser credentials; the driver falls back to Cashier-Paddle's own
    | `cashier.*` keys when these are empty. `webhook.secret` verifies incoming
    | events — its presence is what arms the mirror listener (fail-closed, like
    | Stripe). NOTE the underlying Cashier-Paddle reads the FLAT `cashier.webhook_secret`
    | (not Stripe Cashier's nested `cashier.webhook.secret`); the driver bridges both.
    | `checkout.return_url` is where Paddle returns the buyer after the JS overlay
    | completes. `signature_tolerance` bounds how old a signed webhook may be
    | (seconds); Cashier-Paddle's middleware uses 5s, generous here so a slightly
    | delayed retry still verifies.
    */
    'paddle' => [
        'api_key' => env('PADDLE_API_KEY'),
        'client_side_token' => env('PADDLE_CLIENT_SIDE_TOKEN'),
        'webhook' => [
            'secret' => env('PADDLE_WEBHOOK_SECRET'),
        ],
        'checkout' => [
            'return_url' => env('PADDLE_CHECKOUT_RETURN_URL'),
        ],
        // Replay window for a signed webhook, in seconds. The driver clamps this to
        // [1, 300]; a non-positive value cannot disable freshness. Defaults to 60s
        // (Cashier-Paddle's own route middleware uses 5s).
        'signature_tolerance' => (int) env('PADDLE_WEBHOOK_TOLERANCE', 60),
        // NOTE: PADDLE_RETAIN_KEY and PADDLE_SANDBOX are consumed by Cashier-Paddle's
        // own config (cashier.retain_key / cashier.sandbox) directly from env — the
        // add-on does not re-read them, so they are intentionally not mirrored here.
    ],

    /*
    | Plan definitions (B2). The source sits behind the core's
    | PlanRepositoryContract (rebound to ConfigPlanRepository), so call sites never
    | know config answered. 'source' is a documented marker only — B2 ships the
    | 'config' reader; a 'database' source is YAGNI until a plan-admin UI exists,
    | so there is no 'database' branch in code yet.
    |
    | Shape of one entry (key = the id stored in Company.plan_id):
    |   'pro' => [
    |       'name'     => 'Pro',                 // display name, an English-as-key i18n
    |                                            // string the Vue layer renders with $t()
    |       'features' => ['production.module'], // ENTITLEMENT slugs: what the TARIFF pays
    |                                            // for (a module/capability), NOT a per-action
    |                                            // RBAC permission (that's "who in the company
    |                                            // may", a separate axis). Same module
    |                                            // namespace as the permission catalogue so the
    |                                            // B6 EntitlementResolver can relate them.
    |       'prices'   => [                      // currency-first, then period, in MINOR units
    |           'USD' => ['monthly' => 1900, 'yearly' => 19000],  // cents; null = not sold
    |       ],
    |   ]
    |
    | The definitions below are an EXAMPLE skeleton (neutral SaaS tiers, placeholder
    | prices). A host overrides them after `vendor:publish --tag=larafoundry-billing-config`
    | with its own modules and real prices — exactly as it extends the permission
    | catalogue with its own domain modules.
    */
    'plans' => [
        'source' => env('LARAFOUNDRY_BILLING_PLANS_SOURCE', 'config'),
        'definitions' => [

            // A free tier: no price in any currency (priceFor returns null, not 0,
            // keeping "free" distinct from "unpriced currency"). Grants the base
            // capabilities only.
            'free' => [
                'name' => 'Free',
                'features' => [
                    'core.access',
                ],
                'prices' => [],
            ],

            'pro' => [
                'name' => 'Pro',
                'features' => [
                    'core.access',
                    'api.access',
                    'reports.advanced',
                ],
                'prices' => [
                    'USD' => ['monthly' => 1900, 'yearly' => 19000],
                    'EUR' => ['monthly' => 1900, 'yearly' => 19000],
                    'UAH' => ['monthly' => 79000, 'yearly' => 790000],
                ],
                // Gateway price references (B3, decision D-B3-1). `prices` above are
                // the DISPLAY amounts in minor units; this maps the plan+period to
                // the Stripe price object (`price_xxx`) Cashier's newSubscription()
                // actually charges. They are different things, both required. Kept
                // under the plan so a host edits one place per tier; env-driven so
                // the shipped config carries no real ids. Empty => not sellable via
                // Stripe (the driver throws GatewayOperationException, never charges
                // a wrong/zero amount).
                'gateways' => [
                    'stripe' => [
                        'monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
                        'yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
                    ],
                    // Paddle price ids (`pri_xxx`) for the same plan+period (B4). A
                    // sibling map because the two gateways' price catalogues are
                    // independent; the active gateway is chosen per tenant by region.
                    'paddle' => [
                        'monthly' => env('PADDLE_PRICE_PRO_MONTHLY'),
                        'yearly' => env('PADDLE_PRICE_PRO_YEARLY'),
                    ],
                ],
            ],

            'business' => [
                'name' => 'Business',
                'features' => [
                    'core.access',
                    'api.access',
                    'reports.advanced',
                    'production.module',
                    'priority.support',
                ],
                'prices' => [
                    'USD' => ['monthly' => 4900, 'yearly' => 49000],
                    'EUR' => ['monthly' => 4900, 'yearly' => 49000],
                    'UAH' => ['monthly' => 199000, 'yearly' => 1990000],
                ],
                'gateways' => [
                    'stripe' => [
                        'monthly' => env('STRIPE_PRICE_BUSINESS_MONTHLY'),
                        'yearly' => env('STRIPE_PRICE_BUSINESS_YEARLY'),
                    ],
                    'paddle' => [
                        'monthly' => env('PADDLE_PRICE_BUSINESS_MONTHLY'),
                        'yearly' => env('PADDLE_PRICE_BUSINESS_YEARLY'),
                    ],
                ],
            ],

        ],
    ],

    /*
    | Region routing (B2/B4): country -> currency and country -> gateway. The
    | country is read server-side from Company.country (never a client header or
    | geo-IP) by ConfiguredRegionContext, which the add-on rebinds over the core's
    | DefaultRegionContext. An unmapped country falls back to the core's
    | `larafoundry.billing.region.default_currency` and the default gateway, so a
    | host that ships no map behaves like the free core. The maps below are EXAMPLES
    | (ISO 3166-1 alpha-2 keys); a host sets its own — e.g. routing UA tenants to a
    | MoR gateway and everyone else to the global default.
    */
    'region' => [
        'currencies' => [
            'US' => 'USD',
            'UA' => 'UAH',
            'DE' => 'EUR',
            'FR' => 'EUR',
        ],
        'gateways' => [
            // 'UA' => 'paddle',  // route UA to a MoR gateway once B4 ships it
        ],
    ],

    /*
    | Promo codes (B5). The registry owns validity / personal / single-use; the
    | gateway owns the charged amount (decision D-B5-1 hybrid). `enabled` is the
    | master switch a host flips once it has wired its admin policy. `validate_route`
    | exposes the public POST {prefix}/promo/validate endpoint a checkout UI calls to
    | confirm a code before redirecting to the gateway — OFF by default, and always
    | rate-limited (the donor had no throttle; an unthrottled validate endpoint is a
    | code-enumeration oracle). The host may add `auth` to `middleware`; the named
    | `larafoundry-billing-promo` limiter is appended regardless.
    */
    'promo' => [
        'enabled' => env('LARAFOUNDRY_BILLING_PROMO_ENABLED', false),
        'validate_route' => [
            'enabled' => env('LARAFOUNDRY_BILLING_PROMO_ROUTE', false),
            'prefix' => 'larafoundry-billing',          // POST {prefix}/promo/validate
            'middleware' => ['api'],                    // host may add 'auth'
            'throttle' => ['max' => 5, 'decay' => 60],  // attempts/min (anti-brute-force)
        ],
    ],

    /*
    | Trial / free month (B5, decisions D-B5-2/3). The free month is an APP-SIDE
    | grant: ActivateFreeMonthAction stamps the core's `trial_ends_at` /
    | `free_month_used_at` columns directly — no gateway, no card, a genuine "no
    | credit card" trial that works the same under Stripe or Paddle. Eligibility is
    | per paying subject (its own `free_month_used_at IS NULL` — a company in `teams`
    | mode, the user in `personal`); the offer is monthly-only,
    | matching the donor. `gateway_trial_days` is the optional Stripe trial-days
    | pass-through for a trial on a PAID plan (a separate path from the free month).
    */
    'trial' => [
        'free_month' => [
            'enabled' => env('LARAFOUNDRY_BILLING_FREE_MONTH', false),
            'eligibility' => 'company',                 // D-B5-3 (per-company)
            'period' => 'monthly',                      // monthly-only, donor parity
            'length_days' => 30,
        ],
        'gateway_trial_days' => env('LARAFOUNDRY_BILLING_TRIAL_DAYS'), // optional Stripe pass-through
    ],

    /*
    | Entitlements / feature-gating (B6, the Billing/RBAC loop). The add-on rebinds
    | the core's EntitlementResolver to read a plan's `features()` and aliases the
    | `entitlement` route middleware (use as `entitlement:<feature>`). There is no
    | enable flag here: entitlement gating is intrinsic to billing being on
    | (`larafoundry.billing.enabled`) — with billing off the resolver and middleware
    | stay fully open, mirroring Company::hasAccess().
    |
    | `redirect_route` is where EnsureEntitled sends a BROWSER request that lacks a
    | feature (an "upgrade your plan" page). null => a 403 instead, until the billing
    | UI (B7) ships that page. A JSON request always gets a 403, never a redirect.
    */
    'entitlements' => [
        'redirect_route' => env('LARAFOUNDRY_BILLING_UPGRADE_ROUTE'),
    ],

    /*
    | Access enforcement (B7a). The `billing.access` middleware reads a company's
    | subscription state and decides what to do when it lapses:
    |  - mode=soft (default): pass the request through; the frontend shows an alert
    |    banner from GetBlockStatusAction (no hard lockout — fewer dunning-churn tickets).
    |  - mode=hard: redirect a browser to `blocked_route` (JSON gets a 403).
    | Owner-ban is ALWAYS a hard cascade regardless of mode (a banned payer freezes the
    | whole company). `mode` is matched case-insensitively.
    |
    | IMPORTANT — this gates SUBSCRIPTION LIVENESS, not per-feature access. It does NOT
    | protect paid modules: that is the `entitlement:<feature>` middleware's job (B6). In
    | soft mode an unpaid company reaches the app, and any paid module NOT placed behind
    | `entitlement` would be open. Always gate paid modules with `entitlement`.
    |
    | `exempt_routes` are never blocked (escape hatches that would otherwise loop). A
    | matched route bypasses the FULL check — subscription AND owner-ban — so NEVER mount
    | a paid/sensitive resource under an exempt name. Listed by exact name rather than a
    | broad `billing.*` so a future paid route under that namespace is not silently
    | exempted. `blocked_route` and the entitlement upgrade route are exempt automatically.
    | `expiring_within_days` is the soft warning window.
    */
    'enforcement' => [
        'mode' => env('LARAFOUNDRY_BILLING_ENFORCEMENT_MODE', 'soft'),
        'blocked_route' => env('LARAFOUNDRY_BILLING_BLOCKED_ROUTE', 'billing.blocked'),
        'exempt_routes' => [
            'billing.pricing',
            'billing.checkout',
            'billing.payment.*',    // payment.process and friends
            'billing.history',
            'logout',
            'tenancy.companies.*',  // create + switch company
            'impersonate.leave',
        ],
        'expiring_within_days' => 10,
        // Day-marks the expiry-reminder cron (larafoundry-billing:check-expiring) warns
        // owners on, before subscription_ends_at. Sanitised to positive ints; the cron
        // only mails (never changes access). The host schedules the command ->daily().
        'expiry_reminders' => [10, 3],
    ],

    /*
    | Payment notifications (B7a). When the add-on records a payment from a verified
    | gateway webhook (RecordStripePayment) it fires CompanyPaymentProcessed and — if
    | enabled — emails the company owners a queued success/failure notice (so it never
    | blocks webhook processing). A host that wants its own in-app notifications or
    | templated mail turns this off and listens to CompanyPaymentProcessed itself.
    */
    'notifications' => [
        'enabled' => env('LARAFOUNDRY_BILLING_NOTIFICATIONS', true),
    ],

    /*
    | Owner billing UI routes (B7a). The add-on ships the pricing/checkout/history/
    | blocked routes (Inertia pages a host publishes); loaded only when billing is on
    | AND this is enabled. `prefix` is the URL prefix; the route NAMES stay `billing.*`
    | regardless. `middleware` is the host's group — `web` (session/CSRF for the Inertia
    | flow) + `auth`. A host that wires these routes itself turns this off.
    */
    'routes' => [
        'web' => [
            'enabled' => env('LARAFOUNDRY_BILLING_WEB_ROUTES', true),
            'prefix' => 'billing',
            'middleware' => ['web', 'auth'],
        ],
    ],

    /*
    | Super-admin console (B7b). The add-on registers its own `admin.payments.*` /
    | `admin.promo-codes.*` routes and a menu provider on top of the core operator
    | console, all behind the core's `larafoundry.admin` gate (super-admin via
    | VisitorStatus). Loaded only when billing is on AND this is enabled, so a FREE
    | host never gets the screens. The payments console is READ-ONLY: list + filters
    | + per-currency totals (NO currency conversion — that is B9); the promo console
    | is full CRUD over the B5 backend.
    |
    | `available_countries` powers the payments country filter (ISO 3166-1 alpha-2 =>
    | label). Empty by default — the add-on cannot assume the host's country list — so
    | the Vue degrades the country facet to a free-text input until a host fills it.
    | `user_search` bounds the personal-promo-code user lookup: a minimum query length,
    | a result cap, and a route-level throttle ("max,minutes") — the donor had none.
    */
    'admin' => [
        'enabled' => env('LARAFOUNDRY_BILLING_ADMIN', true),
        // The console route group. Mirrors the core operator console: the session/CSRF
        // `web` group, an authenticated + verified user, the super-admin gate and the
        // super-admin OTP step-up (the same gate the rest of the console sits behind).
        // A host that mounts its admin area differently overrides this.
        'middleware' => ['web', 'auth', 'verified', 'larafoundry.admin', 'larafoundry.admin.otp'],
        'payments_per_page' => 25,
        'promo_codes_per_page' => 20,
        // Cap on the promo-code filter dropdown so the payments page never ships an
        // unbounded list once many historical codes exist.
        'available_promo_codes_limit' => 200,
        'available_countries' => [],
        'user_search' => [
            'min_length' => 2,
            'limit' => 10,
            'throttle' => '30,1',
        ],
    ],

    /*
    | Affiliate / partner program (Ф4.4). A configurable engine, not a single baked
    | policy: this is reusable core (even though the add-on is paid), so every product
    | variant sits behind config and the shipped values are the defaults Dmitry chose.
    | OFF by default (like promo) — a host opts in once it wants a partner program.
    |
    | THREE ORTHOGONAL AXES:
    |  - eligibility — WHO can be a partner;
    |  - commission.trigger — WHICH of a referred company's payments earn a commission;
    |  - commission.basis — HOW MUCH each qualifying payment earns.
    | Trigger and basis are independent (e.g. recurring × per_plan = the plan's amount on
    | every payment inside the window).
    |
    | All money is whole MINOR UNITS in the PAYMENT's currency; there is NO cross-currency
    | conversion (per-currency totals only, like the B9 revenue widget). An unknown
    | plan/currency for the fixed/per_plan bases earns nothing (fail-closed, never throws).
    */
    'affiliate' => [
        'enabled' => env('LARAFOUNDRY_BILLING_AFFILIATE', false),

        // auto        => every user has a referral code (created lazily on first use);
        // self_serve  => a user opts in from their dashboard (pending if require_approval);
        // admin       => only a super-admin creates affiliate records.
        'eligibility' => env('LARAFOUNDRY_BILLING_AFFILIATE_ELIGIBILITY', 'auto'),
        'require_approval' => env('LARAFOUNDRY_BILLING_AFFILIATE_APPROVAL', false),

        'attribution' => [
            // How long the referral cookie lives, and the last-click window. The cookie
            // is encrypted by Laravel's default middleware; the capture route is
            // throttled and always redirects the same way (no code-enumeration oracle).
            'window_days' => (int) env('LARAFOUNDRY_BILLING_AFFILIATE_WINDOW_DAYS', 60),
            'cookie' => 'lf_ref',
            'landing' => env('LARAFOUNDRY_BILLING_AFFILIATE_LANDING', '/'),
            'capture_route' => [
                'enabled' => env('LARAFOUNDRY_BILLING_AFFILIATE_CAPTURE', true),
                'prefix' => 'r',        // GET {prefix}/{code}
                'middleware' => ['web'],
                'throttle' => '30,1',
            ],
        ],

        'commission' => [
            // first_payment => only the company's first successful payment;
            // recurring     => every successful payment within window_months of attribution;
            // lifetime      => every successful payment, forever.
            'trigger' => env('LARAFOUNDRY_BILLING_AFFILIATE_TRIGGER', 'recurring'),
            'window_months' => (int) env('LARAFOUNDRY_BILLING_AFFILIATE_WINDOW_MONTHS', 12),

            // per_plan => an amount per plan, per currency (the default);
            // percent  => a percentage of the net charge (global default + per-affiliate override);
            // fixed    => a flat amount per qualifying payment, per currency.
            'basis' => env('LARAFOUNDRY_BILLING_AFFILIATE_BASIS', 'per_plan'),

            // percent basis: the global rate as a number of PERCENT (may be fractional);
            // a per-affiliate override (affiliates.commission_rate_bp) wins when set. Cast
            // to float so an env-provided string ("20") is a real number, not a string the
            // rate coercion would reject.
            'percent' => [
                'default_rate' => (float) env('LARAFOUNDRY_BILLING_AFFILIATE_PERCENT', 20),
            ],

            // fixed basis: currency => minor units. A currency not listed earns nothing.
            'fixed' => [
                // 'USD' => 1000,
                // 'EUR' => 1000,
            ],

            // per_plan basis: plan id => currency => minor units. The example mirrors the
            // example plans above; a host edits this alongside its real plans. A plan or
            // currency not listed earns nothing.
            'per_plan' => [
                'pro' => ['USD' => 500, 'EUR' => 500, 'UAH' => 20000],
                'business' => ['USD' => 1500, 'EUR' => 1500, 'UAH' => 60000],
            ],
        ],

        // Super-admin payout console + partner dashboard list size.
        'console' => [
            'per_page' => 25,
        ],

        // The partner's own dashboard (their code/link/earnings). A tenant-level route +
        // menu item; shown to every user in auto/self_serve eligibility (in admin
        // eligibility the partner reaches it by a direct link). `web` for the Inertia
        // session flow + `auth`; a host that mounts it differently overrides this.
        'dashboard' => [
            'enabled' => env('LARAFOUNDRY_BILLING_AFFILIATE_DASHBOARD', true),
            'prefix' => 'affiliate',
            'middleware' => ['web', 'auth'],
        ],
    ],

];
