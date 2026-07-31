<?php

declare(strict_types=1);
use Dmitryisaenko\LaraFoundry\Admin\Http\Resources\AdminUserResource;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy (phase 1.2)
    |--------------------------------------------------------------------------
    | mode:
    |   'teams'    — multi-company, tenant = Company (like kohana.io). Enables the
    |                company-creation wizard, invitations, company switching.
    |   'personal' — tenant = the User itself (no companies). The company flow is
    |                not registered; BelongsToTenant filters by user_id.
    |
    | company_model — the company model. The host extends the base one and supplies
    |                 its own (`App\Models\Company extends ...\Tenancy\Models\Company`).
    | foreign_key   — the tenant FK column name on domain models. In teams it is
    |                 'company_id'; BelongsToTenant reads it from here. In personal
    |                 the trait filters by user_id regardless of this value.
    | invitation_expiry_days — employee invitation lifetime.
    | routes_without_active_tenant — route names (fnmatch) available to a company
    |                 user WITHOUT a selected active company (e.g. a request to
    |                 remove oneself from a company). Used by EnsureActiveTenant.
    */
    'tenancy' => [
        'mode' => env('LARAFOUNDRY_TENANCY_MODE', 'teams'),
        'company_model' => Company::class,
        'foreign_key' => 'company_id',
        'invitation_expiry_days' => 7,
        'routes_without_active_tenant' => [
            'tenancy.employees.request-removal',
            'tenancy.employees.cancel-removal',
        ],

        // Where to land after a company is set up / an invite is accepted. The
        // dashboard is host territory, so the core only knows a route name (or
        // path) to redirect to; default '/' keeps the package self-contained.
        'home_route' => env('LARAFOUNDRY_TENANCY_HOME', '/'),

        // Route name EnsureActiveTenant redirects a NON-owner member to when their
        // active company has been archived by its owner (phase 7). The host owns
        // the archived-company screen (an owner unarchives there; a member sees an
        // explanation), so the core only knows a route name. Null falls back to the
        // company-create flow with a flashed message. The company OWNER is never
        // redirected — they pass the boundary so they can unarchive.
        'archived_redirect_route' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing (seam, phase 3.1)
    |--------------------------------------------------------------------------
    | The free core ships only the SEAM: contracts + driver manager + null
    | implementations. Real payments (Cashier Stripe/Paddle, promo, trial UI,
    | portal, metrics) live in the paid add-on `dmitryisaenko/larafoundry-billing`,
    | which plugs into these contracts.
    |
    | enabled — the master access switch. false (default) = free self-host with no
    |           restrictions: Company::hasAccess() is always true, gates do not
    |           block. true (usually together with the add-on) = hasAccess() reads
    |           the real subscription state from the companies billing columns
    |           (fail-closed: no valid trial/subscription → no access).
    |
    | gateway.default — the default payment gateway driver name. The core registers
    |           only 'null' (accepts nothing). The add-on/host register
    |           'stripe'/'paddle'/a local PSP via PaymentGatewayManager::extend()
    |           and point to it here.
    |
    | region.default_currency — the default currency (ISO 4217) when there is no
    |           country→currency mapping. Per-country prices/currencies/gateway
    |           selection are the add-on/host's domain via their RegionContext.
    */
    'billing' => [
        'enabled' => env('LARAFOUNDRY_BILLING_ENABLED', false),

        'gateway' => [
            'default' => env('LARAFOUNDRY_BILLING_GATEWAY', 'null'),
        ],

        'region' => [
            'default_currency' => env('LARAFOUNDRY_BILLING_CURRENCY', 'USD'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upsell (free-core monetization stubs, phase 4)
    |--------------------------------------------------------------------------
    | The free core reserves the Payments / Affiliates / Promo console slots with
    | inert stub screens (the real features ship in the paid billing add-on, which
    | overrides these screens entirely). Each stub shows an empty state and, while
    | billing is not enabled, a call to action pointing at where to obtain the paid
    | add-on.
    |
    | billing_url — where the upsell call to action sends the operator. The paid
    |               add-on replaces the stub screens, so this only matters for the
    |               free core.
    */
    'upsell' => [
        // Where the free-core upsell stubs point users to obtain the paid billing add-on.
        // Overridden by the paid add-on (which replaces these stub screens entirely).
        'billing_url' => env('LARAFOUNDRY_UPSELL_BILLING_URL', 'https://larafoundry.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    | ONE source of truth: locale = an ISO 639-1 code ('en', 'uk', 'de'). This code
    | is used EVERYWHERE — in the URL, cookie, DB, translations. No 'ua', 'English'
    | as a key, no parallel lists. Everything else (language name, flag) is metadata
    | of a SINGLE locale, not separate arrays.
    |
    | available  — the allowlist of available locales (ISO 639-1). The only list
    |              EVERYTHING validates against. An invalid code (e.g. 'ua') simply
    |              won't apply — SetLocale discards it.
    | default    — the fallback locale (must be in available).
    | cookie     — the cookie name where a guest's choice is stored.
    | locales    — per-locale metadata (native name, flag) for the UI switcher.
    | detect_map — ONLY exceptions for browser auto-detection. A code that matches
    |              one of available is taken as-is and need NOT be listed here. Only
    |              the "non-identical" cases live here (e.g. a Russian-language
    |              browser → Ukrainian interface).
    | geoip      — an optional IP geo-resolver. OFF by default: a synchronous
    |              external HTTP call on every request — slow, leaks the IP to a
    |              third party, a point of failure. The host may enable it and supply
    |              its own class (implements LocaleGeoResolver). The country→locale
    |              mapping is that resolver's job, not the core's (countries are a
    |              host business entity).
    */
    'locale' => [
        'available' => ['en', 'uk'],
        'default' => env('LARAFOUNDRY_LOCALE', 'en'),
        'cookie' => 'locale',
        'locales' => [
            'en' => ['native' => 'English', 'flag' => '🇬🇧'],
            'uk' => ['native' => 'Українська', 'flag' => '🇺🇦'],
        ],
        'detect_map' => [
            // 'ru' => 'uk',
        ],
        'geoip' => [
            'enabled' => false,
            'resolver' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    | admin_ips         — list of IPs (CSV or array) allowed to enter the
    |                     admin/auth zone in production. Empty = no restriction.
    |                     Used by the RestrictAuthByIp middleware.
    | super_admin       — the platform super-admin (phase 1.4):
    |   email           — the operator's exclusive email. When set, ONLY it
    |                     (together with the is_admin flag) gets admin status in
    |                     VisitorStatus, AND this email is reserved: it cannot
    |                     register as an ordinary user and cannot own a company
    |                     (the operator identity is separate from tenant identities).
    |                     Empty = fall back to auth.failed_login.admin_email
    |                     (backward compatibility), then "the flag is enough".
    |   console_route   — the route name the super-admin is isolated into
    |                     (RedirectSuperAdminToConsole redirects them here from
    |                     tenant zones).
    |   allowed_routes  — route names (fnmatch) available to the super-admin OUTSIDE
    |                     the console redirect (the console itself, OTP gate, PIN, logout,
    |                     and the operator pull-out's own preference actions: language
    |                     switch + the allowlisted ui-settings PUT for theme).
    |   allowed_paths   — request PATHS (fnmatch) available to the super-admin outside
    |                     the console redirect, for packages that register routes with
    |                     no name (allowed_routes cannot match those). Empty by default.
    | email_verification — settings for the EnsureEmailIsVerified middleware:
    |   redirect_route  — where to send an unverified user.
    |   except_routes   — route names (fnmatch patterns) available without verification.
    |   except_prefixes — path prefixes available without verification.
    */
    'security' => [
        'admin_ips' => env('LARAFOUNDRY_ADMIN_IPS', ''),
        'super_admin' => [
            'email' => env('LARAFOUNDRY_SUPER_ADMIN_EMAIL'),
            'console_route' => 'admin.dashboard.index',
            'allowed_routes' => [
                'admin.*',
                'pin.*',
                'logout',
                'password.confirm*',
                // The operator pull-out (in AdminLayout) lets the operator switch
                // language and toggle the theme without leaving the console. Both
                // are per-user, self-scoped preference endpoints — not tenant
                // surface — so the confine allows them explicitly (they are named
                // routes outside the admin.* namespace, so the pattern above would
                // otherwise bounce the operator to the console on click).
                'larafoundry.language.switch',
                'profile.ui-settings.update',
            ],

            // Paths (glob, matched with Request::is) the super-admin may keep
            // instead of being bounced to the console. allowed_routes above matches
            // by route NAME, and operator tooling routinely registers routes without
            // one — Telescope names only its SPA shell, every data call it makes
            // (/telescope/telescope-api/*) is unnamed, so the name list cannot reach
            // it and the XHR gets a 403 that reads like a broken tool. List such
            // surfaces here. An empty array = exactly the behaviour before this key
            // existed.
            //
            // Matching rules, all of which bite in practice:
            //   - `*` is the ONLY wildcard (it crosses slashes); `?` and `[abc]` are
            //     literals, unlike real fnmatch().
            //   - These are GLOBS, not prefixes — unlike email_verification's
            //     `except_prefixes`, 'telescope' does NOT cover 'telescope/anything'.
            //     List both: 'telescope', 'telescope/*'.
            //   - Keep patterns narrow. 'telescope*' also matches 'telescope-notes'
            //     and any other path that merely starts with those letters.
            //   - A leading slash is optional ('/telescope' == 'telescope'); '/' on
            //     its own means the root path.
            //   - '' and '*' are ignored: '*' would switch the confinement off
            //     wholesale, which no list of tool paths is meant to say.
            //
            // WARNING: a path here switches off the CONFINEMENT, not authorization —
            // and, if your tooling sits on the `web` group, also the operator OTP
            // step-up gate, which only guards the core's own /admin routes. Only put
            // surfaces that gate themselves (Telescope — the `viewTelescope` gate,
            // log-viewer — its `LogViewer::auth()` callback) AND make sure that gate
            // is actually closed in production. A surface with no gate of its own
            // becomes reachable by the operator, which breaks precisely the
            // console/tenant separation this middleware exists to enforce.
            //
            // Example (host side): ['telescope', 'telescope/*', 'log-viewer', 'log-viewer/*']
            'allowed_paths' => [],

            // OTP step-up gate (phase 1.4): the operator console requires a
            // fresh two-factor code once per session (EnsureAdminOtpVerified),
            // on top of any 2FA challenge at login — so OAuth logins (which skip
            // Fortify's login challenge) still prove OTP before reaching /admin.
            //
            // require_otp        — master switch. true = enforce the gate.
            // two_factor_setup_route — route NAME of the 2FA-enrolment screen a
            //   super-admin without confirmed 2FA is sent to. Defaults to the
            //   core's in-console operator security page (`admin.security.show`),
            //   which lives OUTSIDE the OTP step-up gate so an un-enrolled operator
            //   can reach it to enrol — closing the chicken-and-egg. A host may
            //   override this to point at its own enrolment screen; setting it
            //   empty makes the gate deny with 403 instead (fail closed).
            'require_otp' => env('LARAFOUNDRY_ADMIN_REQUIRE_OTP', true),
            'two_factor_setup_route' => env('LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE', 'admin.security.show'),
        ],
        'email_verification' => [
            'redirect_route' => 'verification.notice',
            'except_routes' => [
                'verification.*',
                'logout',
            ],
            'except_prefixes' => [
                'profile',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session PIN-lock (phase 1.4)
    |--------------------------------------------------------------------------
    | Telegram-style quick re-entry: after idle time (or a manual lock) the active
    | web session requires a short PIN instead of a full re-login. The PIN is
    | optional — any user enables it in their profile; stored as a hash.
    |
    | enabled         — the master switch. false = the PIN mechanic is not applied
    |                   (CheckPinLock middleware = no-op), even if a PIN is set.
    | length          — PIN length (digits). The donor default is 4.
    | idle_timeout    — seconds of inactivity after which the session auto-locks
    |                   (only the session that went idle).
    | max_attempts    — wrong entries in a row before input is temporarily locked.
    | lockout_minutes — how many minutes PIN INPUT is locked after exceeding
    |                   max_attempts (anti-brute-force; the donor lacked this).
    */
    'pin' => [
        'enabled' => env('LARAFOUNDRY_PIN_ENABLED', true),
        'length' => 4,
        'idle_timeout' => 1800,
        'max_attempts' => 5,
        'lockout_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication (phase 1.1)
    |--------------------------------------------------------------------------
    | The core builds its auth flow on top of Laravel Fortify (login/register/
    | reset/verify/password-confirm + per-user TOTP two-factor). These options
    | tune the pieces the core adds AROUND Fortify; Fortify's own behaviour
    | lives in the host's published config/fortify.php.
    |
    | password_min_length — floor for new/reset passwords. The core also applies
    |                       Laravel's Password::defaults() rules on top.
    | oauth.enabled        — master switch for the Socialite redirect/callback.
    | oauth.providers      — providers exposed by the core's OAuth routes. Keys
    |                        and secrets live in config/services.* (host-owned).
    | oauth.link_existing  — when an OAuth identity resolves to an email that
    |                        already has a LOCAL account, whether to auto-link.
    |                        Default false: auto-linking on email alone is an
    |                        account-takeover vector (donor had this hole). When
    |                        false, the callback refuses and tells the user to
    |                        sign in locally first, then link from settings.
    | failed_login.notify  — email/log channel notified on admin login failures.
    | two_factor.confirm   — require the user to confirm TOTP enrolment with a
    |                        live code before it is active (recommended).
    | presentation         — how the guest auth screens (login/register/forgot/
    |                        reset) are surfaced: `page` (a full page, the
    |                        default) or `modal` (an overlay over the host's
    |                        content). Read by the AuthScreen frontend wrapper via
    |                        the shared `auth_presentation` prop; an unknown value
    |                        falls back to `page` so the surface never breaks.
    */
    'auth' => [
        'password_min_length' => 8,

        'presentation' => env('LARAFOUNDRY_AUTH_PRESENTATION', 'page'), // page | modal

        // QR cross-device login (phase 1.4 part 2). The web side shows a QR an
        // already-authenticated device scans to approve a login.
        //  enabled              — master switch for the QR routes + Login QR tab.
        //  ttl_minutes          — how long a freshly generated code stays valid.
        //  absolute_ttl_minutes — hard cap measured from creation, so a code that
        //                         keeps sliding forward still dies (donor hole #4).
        //  size                 — rendered QR side length in px.
        //  poll_interval_ms     — how often the web side polls for approval.
        'qr' => [
            'enabled' => env('LARAFOUNDRY_QR_ENABLED', true),
            'ttl_minutes' => 5,
            'absolute_ttl_minutes' => 15,
            'size' => 400,
            'poll_interval_ms' => 2000,
        ],

        // Passwordless email sign-in (magic-link). A first-class login method next
        // to OAuth/QR/passkeys: enter an email → a one-time signed link is mailed →
        // clicking it logs in. The same click is registration (a first sign-in by
        // an unknown email creates the account, email pre-verified by the click),
        // and it is the ONLY way to authenticate the reserved super-admin email
        // (which OAuth and public registration refuse — ownership is proven by
        // receiving the mail).
        //
        //  enabled              — master switch for the routes + the `auth_magic_link`
        //                         shared prop. false = the routes are not registered
        //                         at all (a disabled feature 404s, it does not just
        //                         hide the UI), mirroring the QR switch.
        //  ttl_minutes          — how long a freshly issued link stays valid; also
        //                         the temporary-signed-URL expiry.
        //  absolute_ttl_minutes — a hard cap measured from creation (donor hole #4,
        //                         as in qr) so a token can never outlive this.
        //  throttle             — "attempts,minutes" for the request endpoint, keyed
        //                         per (email + IP) via a named limiter (same idiom as
        //                         Fortify's login limiter) — an attacker cannot spam
        //                         one address nor sweep many from one IP.
        //  invalidate_previous  — a new request marks the email's earlier unused
        //                         tokens consumed, so only the latest link works.
        'magic_link' => [
            'enabled' => env('LARAFOUNDRY_MAGIC_LINK_ENABLED', false),
            'ttl_minutes' => 15,
            'absolute_ttl_minutes' => 30,
            'throttle' => '5,1',
            'invalidate_previous' => true,
        ],

        // Social sign-in (Socialite). The controller is provider-agnostic: any
        // slug listed here is accepted as long as a Socialite driver is
        // registered and `enabled` is true. The frontend renders one button per
        // listed provider (config-driven, shared as the `auth_oauth` Inertia
        // prop) — adding a provider here is all the core needs.
        //
        //  enabled   — master switch for the OAuth routes + the Login buttons.
        //  providers — the blessed allow-list. The default set is the providers
        //              Socialite ships built-in (no extra package): google,
        //              facebook and twitter (OAuth 1.0a). A button only actually
        //              works once the host supplies that provider's credentials
        //              in `config/services.php` + `.env`; the core ships none.
        //              Community providers (apple, microsoft, …) need a
        //              `socialiteproviders/*` package the host installs — then
        //              just add the slug here AND map it under `community_drivers`
        //              below. The core auto-registers the package's driver (no
        //              manual SocialiteWasCalled listener in the host anymore).
        //  community_drivers — slug => handler FQCN for socialiteproviders/*
        //              packages. For every slug here that is ALSO in `providers`
        //              AND whose handler class is installed, the core registers
        //              the SocialiteWasCalled listener for it. See the key below.
        //  link_existing — when true, an OAuth login whose email matches an
        //              existing local account links to it instead of being
        //              refused (the anti-takeover default is false).
        'oauth' => [
            'enabled' => env('LARAFOUNDRY_OAUTH_ENABLED', false),
            'providers' => ['google', 'facebook', 'twitter'],
            'link_existing' => false,
            'redirect_after_login' => '/',

            // Community Socialite drivers (socialiteproviders/* packages). The
            // core auto-registers the SocialiteWasCalled listener for any slug
            // here that is ALSO listed in `providers` AND whose handler class is
            // actually installed (class_exists guard) — so a host only needs
            // `composer require socialiteproviders/<x>` + creds, no manual event
            // listener. A host may add/override entries for any other community
            // package. Values are plain strings (never ::class) so a missing
            // package never trips the autoloader. Keys MUST stay static config —
            // the FQCN is registered verbatim, so it must never be request-derived.
            //
            // Default map ships apple + microsoft, the two community providers
            // verified against their current packages. Deliberately NOT shipped:
            //   - 'linkedin' — Socialite 5 has a native `linkedin-openid` driver,
            //     so the community `linkedin` driver is redundant/conflicting.
            //   - 'twitter'  — the OAuth-2 X community package registers driver
            //     name 'twitter', colliding with the native OAuth-1.0a `twitter`
            //     already in `providers`. A host that wants OAuth-2 X must resolve
            //     the slug first (e.g. expose it as 'x') before mapping it here.
            'community_drivers' => [
                'apple' => '\SocialiteProviders\Apple\AppleExtendSocialite',
                'microsoft' => '\SocialiteProviders\Microsoft\MicrosoftExtendSocialite',
            ],
        ],

        // Security alert when the super-admin account is the target of a failed
        // auth step. One unified signal — AdminAccessAttemptFailed — is raised
        // for a bad password/lockout, a wrong operator-console OTP, or a wrong
        // session PIN. The core's neutral default delivery is mail; a host adds
        // extra channels (e.g. Telegram) by listening to that same event and
        // appending its channel name to `channels` below — no core change.
        //
        // Three independent axes, all checked via AdminAccessAlertPolicy so the
        // "failure type x channel" matrix lives in one place for every channel:
        'failed_login' => [
            // Master on/off switch for all admin-access alerts.
            'notify_admin' => env('LARAFOUNDRY_NOTIFY_LOGIN_FAIL', false),

            // The super-admin email an alert protects. Falls back to
            // security.super_admin.email via VisitorStatus when left null.
            'admin_email' => env('LARAFOUNDRY_ADMIN_EMAIL'),

            // Which failure TYPES raise an alert. For "OTP only" use ['admin_otp'].
            'alert_on' => ['password', 'lockout', 'admin_otp', 'pin'],

            // Which CHANNELS deliver. The core knows 'mail'; a host listening on
            // AdminAccessAttemptFailed appends its own (e.g. 'telegram') and
            // checks AdminAccessAlertPolicy::shouldAlert($step, 'telegram').
            'channels' => ['mail'],

            // Optional: a host may swap the core mail notification for its own
            // subclass of AdminLoginAttemptNotification (FQCN). Null = core default.
            'notification' => null,
        ],

        'two_factor' => [
            'confirm' => true,
        ],

        // Route name the EnsureAccountIsActive middleware redirects blocked /
        // deleted users to. Null falls back to '/' with a flashed error.
        'blocked_redirect_route' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator console (phase 2.3)
    |--------------------------------------------------------------------------
    | Settings for the super-admin console (user management, impersonation,
    | company management).
    |
    | users_per_page     — page size for the admin user list.
    | companies_per_page — page size for the admin company list (phase 3.3).
    | subscription_expiring_within_days — a still-active subscription whose end
    |   date falls inside this many days is classified as "expiring" in the
    |   company list (status badge + filter). Display-only; access is still
    |   granted until the end date passes (Company::hasAccess()).
    | active_within_days — a user with tracked activity inside this many days is
    |   counted as "active" in the dashboard user widget (phase 3.4).
    | dashboard_activity_limit — how many recent admin-log events the dashboard
    |   activity widget shows (phase 3.4). Clamped to a sane ceiling in code.
    */
    'admin' => [
        'users_per_page' => 21,
        'companies_per_page' => 21,
        'subscription_expiring_within_days' => 7,
        'active_within_days' => 30,
        'dashboard_activity_limit' => 10,

        // Host seam (phase 7): the resource that serialises users for the admin
        // list. Point this at an AdminUserResource subclass that overrides
        // `extra()` to append host columns (e.g. "used demo?") without forking
        // the Vue table. Must extend AdminUserResource or it is ignored.
        'user_resource' => AdminUserResource::class,

        // Which optional user-list columns the operator console shows. Default is
        // empty = a privacy-clean table (name/email/country/language/auth/companies/
        // registered/last-activity/status) suitable for a public package and GDPR.
        // A host opts extra columns in, once, in its published config. Recognised
        // tokens: 'phone', 'sex', 'age', 'social'. Unknown tokens are ignored.
        // ('social' lights up in phase 3b once social-link storage ships.)
        'user_columns' => [],

        // Hide the platform super-admin (operator) account from every user LIST
        // and count, so the operator never appears among ordinary users (the admin
        // console list + type-ahead, the dashboard user aggregates, the ticket
        // user picker). This is a LIST-only filter via the `withoutSuperAdmin`
        // scope — it never touches auth/login, by-id lookups or impersonation, so
        // the operator can still be found and act. Set false to include the
        // operator in listings (e.g. a single-admin dev box that wants to see it).
        'exclude_super_admin_from_lists' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inertia shared props
    |--------------------------------------------------------------------------
    | Controls the core part of Inertia::share (flash/locale/translations/…).
    | intended_url:
    |   except_routes — routes (names) that must NOT be stored as the
    |                   "intended url" (JSON endpoints, polling, etc.).
    */
    'inertia' => [
        'intended_url' => [
            'except_routes' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile (phase 5.1)
    |--------------------------------------------------------------------------
    | ui_settings — the ALLOWLIST of user UI preferences stored in the
    | users.ui_settings column (JSON). In the donor ANY key/value was written to
    | this column (recon finding #2) — here it is fail-closed: ONLY registered keys
    | are written and read, each with its own type (the value is cast, not trusted)
    | and an optional `in` enum. The host adds its own preferences by publishing
    | this config; domain settings (per-company/app) belong to the Settings module
    | (sub-phase B), not here.
    |
    |   type    — 'boolean' | 'integer' | 'float' | 'string' (value cast).
    |   default — the default value when the key is not yet stored.
    |   in      — (optional) the allowed set of values (enum).
    |   label   — (optional) the human field label. It is an i18n key (translated
    |             on the front by the shared dictionary), so ship a plain-English
    |             string here. Falls back to the raw key when absent — which is why
    |             the core sets one for every key it ships: a host must never see a
    |             machine key (theme, date_format…) leak into the appearance form.
    |   labels  — (optional, enum keys only) per-value human labels, value => i18n
    |             key. The option select shows these instead of the raw token
    |             (dmy, 24h…); an unlisted value falls back to the token itself.
    */
    'profile' => [
        // Recognised social-link platforms (phase 3b). The single source of truth
        // for the write-side whitelist AND the front-end select/icons: the admin
        // user form receives this list and the request validates the submitted
        // `platform` against it, so a host that publishes this config extends both
        // at once. Unknown platforms are rejected on write.
        'social_platforms' => [
            'website',
            'twitter',
            'linkedin',
            'github',
            'facebook',
            'instagram',
            'telegram',
            'youtube',
        ],

        'ui_settings' => [
            'theme' => [
                'type' => 'string',
                'default' => 'system',
                'in' => ['light', 'dark', 'system'],
                'label' => 'Theme',
                'labels' => ['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'],
            ],
            'sidebar_collapsed' => [
                'type' => 'boolean',
                'default' => false,
                'label' => 'Collapse sidebar',
            ],
            'table_density' => [
                'type' => 'string',
                'default' => 'comfortable',
                'in' => ['comfortable', 'compact'],
                'label' => 'Table density',
                'labels' => ['comfortable' => 'Comfortable', 'compact' => 'Compact'],
            ],
            // How dates are rendered for this user. 'auto' (default) derives the
            // order from the active app locale (en→month-first, uk→day-first)
            // with a browser fallback — keeping today's behaviour — while the
            // explicit values let a user override it regardless of language
            // (format ≠ language: a Ukrainian in the US, an English speaker in
            // Europe). dmy=DD.MM.YYYY, mdy=MM/DD/YYYY, iso=YYYY-MM-DD. The shared
            // `useDateFormat()` composable reads this preference everywhere.
            'date_format' => [
                'type' => 'string',
                'default' => 'auto',
                'in' => ['auto', 'dmy', 'mdy', 'iso'],
                'label' => 'Date format',
                'labels' => [
                    'auto' => 'Automatic',
                    'dmy' => 'DD.MM.YYYY',
                    'mdy' => 'MM/DD/YYYY',
                    'iso' => 'YYYY-MM-DD',
                ],
            ],
            // How the TIME part is rendered, independent of the date order above.
            // 'auto' (default) keeps the prior behaviour — the clock follows the
            // date format / locale (US month-first → 12h, everything else → 24h;
            // in auto date mode Intl decides). '24h'/'12h' force it regardless, so
            // a user who wants a 24-hour clock with a US date order (or the
            // reverse) can have it. Read together with date_format by the shared
            // `useDateFormat()` composable's formatDateTime().
            'time_format' => [
                'type' => 'string',
                'default' => 'auto',
                'in' => ['auto', '24h', '12h'],
                'label' => 'Time format',
                'labels' => ['auto' => 'Automatic', '24h' => '24-hour', '12h' => '12-hour'],
            ],
        ],

        // Personal-data export (phase 5.3): how often a user may pull a full JSON
        // dump of their own data. Format "<attempts>,<minutes>" — default 3/day.
        'data_export' => [
            'throttle' => '3,1440',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings (phase 5.1)
    |--------------------------------------------------------------------------
    | Registry of generic key-value settings (the larafoundry_settings table). The
    | SINGLE source of truth: ONLY registered keys are writable/readable
    | (fail-closed) — an arbitrary key never reaches the store. Each key:
    |
    |   scope      — app | company | user (where the value lives and who owns it:
    |                app=super-admin, company=active company [RBAC
    |                company.settings.*], user=the user itself).
    |   type       — boolean | integer | float | string | array (value cast).
    |   default    — the default value when nothing is stored.
    |   validation — the Laravel validation rule for the value on write.
    |   in         — (optional) enum of allowed values (for a select in the UI).
    |   public     — (optional, app only) whether to expose the value to the front
    |                end (the host shares it via Inertia::share).
    |   form       — (optional, default true) whether to show the key in the
    |                self-service form. false = the key is stored, but written by
    |                another flow, not the form (e.g. consent flags — written by the
    |                phase 5.3 consent wizard).
    |
    | The host adds its own settings by publishing this config. The core ships no
    | domain settings — only infrastructural ones + seams for phase 5.3 (consent).
    */
    'settings' => [

        // App scope — platform-level. RESERVED / not yet wired: there is no
        // consumer reading these two keys (support_email is not surfaced anywhere;
        // signups_enabled does not gate registration), and the admin Settings
        // screen that used to edit them has been removed. They are kept as the
        // registered seam + `public` share contract for a future feature — when a
        // consumer lands, re-add the admin.settings route + controller and the
        // editor page. Until then nothing writes them (defaults apply).
        'support_email' => [
            'scope' => 'app',
            'label' => 'Support email',
            'type' => 'string',
            'default' => null,
            'validation' => ['nullable', 'email', 'max:255'],
            'public' => true,
        ],
        'signups_enabled' => [
            'scope' => 'app',
            'label' => 'Allow new sign-ups',
            'type' => 'boolean',
            'default' => true,
            'validation' => ['boolean'],
            'public' => true,
        ],

        // Company scope — editable by the owner / a role with company.settings.update.
        // `in` drives the form dropdown (schemaForScope -> options); the
        // `timezone` rule still validates the value, so the full IANA list keeps
        // options and validation consistent without hardcoding a curated subset.
        'timezone' => [
            'scope' => 'company',
            'label' => 'Time zone',
            'type' => 'string',
            'default' => 'UTC',
            'in' => timezone_identifiers_list(),
            'validation' => ['string', 'timezone'],
        ],

        // User scope — editable by the user itself.
        'email_notifications' => [
            'scope' => 'user',
            'label' => 'Email notifications',
            'type' => 'boolean',
            'default' => true,
            'validation' => ['boolean'],
        ],

        // User scope — consent seams for phase 5.3 (Legal/GDPR). Registered so the
        // store can hold them, but NOT in the self-service form (form=false):
        // they are written by the phase 5.3 consent flow, not the settings page.
        'cookie_consent' => [
            'scope' => 'user',
            'type' => 'boolean',
            'default' => false,
            'validation' => ['boolean'],
            'form' => false,
        ],

        // User scope — the getting-started checklist's "hidden" flag (phase 5.4).
        // Registered so the store can hold it, but NOT in the self-service form
        // (form=false): it is written by the dismiss endpoint, not the settings
        // page. The checklist is gentle — a user hides it for good with one click.
        'onboarding.dismissed' => [
            'scope' => 'user',
            'type' => 'boolean',
            'default' => false,
            'validation' => ['boolean'],
            'form' => false,
        ],
        'terms_accepted_version' => [
            'scope' => 'user',
            'type' => 'string',
            'default' => null,
            'validation' => ['nullable', 'string', 'max:50'],
            'form' => false,
        ],
        'terms_accepted_at' => [
            'scope' => 'user',
            'type' => 'string',
            'default' => null,
            'validation' => ['nullable', 'date'],
            'form' => false,
        ],
    ],

];
