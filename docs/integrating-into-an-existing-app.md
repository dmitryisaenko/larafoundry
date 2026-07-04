# Integrating LaraFoundry into an existing Laravel app

This guide is the end-to-end walkthrough for bolting `dmitryisaenko/larafoundry` onto an **already-existing** Laravel application — as opposed to the per-module reference in [`README.md`](README.md) + [`modules/`](modules/). It is written around one concrete, common profile, but each section calls out the general rule so you can adapt it.

**Worked profile used throughout:**

- **No companies / no teams** — every user is their own tenant (`tenancy.mode = personal`).
- **Login only** — no public self-registration; users are provisioned by the operator or seeded.
- **Sign-in:** email+password, **Google OAuth**, and **QR cross-device** login.
- **One super-admin = a fixed host email** (the operator), with a small host-built admin section ("Statistics / Logs") added into the core operator console.
- Optional later: **mobile app** via Sanctum tokens; **Cashier** billing on top.

> The core ships server-rendered **Inertia + Vue 3** pages. Your host frontend must be Inertia + Vue 3 — a Blade-only or React host cannot consume the shipped pages.

---

## 1. Prerequisites (host stack)

| Layer | Requirement | Note |
|---|---|---|
| PHP | `^8.2` | core requires it |
| Laravel | `^12.0 \|\| ^13.0` | `illuminate/*` constraint |
| Inertia (server) | `inertiajs/inertia-laravel ^2.0 \|\| ^3.0` | pulled in by core |
| Fortify | `laravel/fortify ^1.25 \|\| ^2.0` | auth engine; core wires views/actions onto it |
| Sanctum | `laravel/sanctum ^4.0` | QR verify endpoint + mobile tokens |
| Socialite | `laravel/socialite ^5.0` | Google OAuth |
| Frontend (npm) | `@inertiajs/vue3`, `vue ^3.4`, `vue-i18n ^11`, `ziggy-js ^2`, Vite, `tailwindcss ^4` | host-owned |

`composer require dmitryisaenko/larafoundry` brings the PHP deps (Fortify, Sanctum, Socialite, Ziggy, `intervention/image`, `laravolt/avatar`, `spatie/laravel-activitylog`, `bacon/bacon-qr-code`, `ezyang/htmlpurifier`). The **npm** peers are yours to add.

---

## 2. Install (PHP side)

The single provider `Dmitryisaenko\LaraFoundry\LaraFoundryServiceProvider` **auto-discovers** — it merges configs, loads migrations and routes, and registers middleware aliases. No manual registration.

```bash
composer require dmitryisaenko/larafoundry

php artisan larafoundry:install          # publishes config/larafoundry.php + config/larafoundry-permissions.php
php artisan migrate                       # runs the package's auto-loaded migrations
php artisan larafoundry:permissions:sync  # seeds the permission catalog, the `authenticated` role + role templates

php artisan fortify:install               # host gets config/fortify.php + FortifyServiceProvider
php artisan vendor:publish --tag=larafoundry-pages   # copies the Vue pages into resources/js/Pages
```

Migrations and routes are **auto-loaded from the package — you do not publish them.** Publish more configs only to override a default:

| Tag | Publishes to | Override |
|---|---|---|
| `larafoundry-config` | `config/larafoundry.php` | locale, **tenancy**, security/super-admin, auth/oauth/qr, **settings registry** |
| `larafoundry-permissions` | `config/larafoundry-permissions.php` | RBAC catalog |
| `larafoundry-pages` | `resources/js/Pages/*` | the Inertia Vue pages (re-publish + rebuild after a UI-phase bump) |
| `larafoundry-lang` | `lang/vendor/larafoundry/*` | translations |
| `larafoundry-media` / `larafoundry-activitylog` / `larafoundry-notifications-config` / `larafoundry-tickets-config` / `larafoundry-email-config` / `larafoundry-legal-config` | matching `config/*.php` | module configs |
| `larafoundry-mail-views` | `resources/views/vendor/larafoundry/mail/*` | mail templates |

> Note the tag naming: the notifications/tickets/email/legal **config** tags carry a `-config` suffix (`larafoundry-notifications-config`, …); the others are bare `larafoundry-<name>`.

---

## 3. `.env` for this profile

```dotenv
# --- Tenancy: every user is their own tenant, no companies ---
LARAFOUNDRY_TENANCY_MODE=personal

# --- Locale ---
LARAFOUNDRY_LOCALE=en

# --- Super-admin operator (the fixed host email) ---
LARAFOUNDRY_SUPER_ADMIN_EMAIL=operator@yourcrm.test
LARAFOUNDRY_ADMIN_REQUIRE_OTP=true
LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE=two-factor.show     # host route NAME of your 2FA-enrolment screen

# --- Google OAuth (only Google for this app) ---
LARAFOUNDRY_OAUTH_ENABLED=true
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_CLIENT_REDIRECT=https://yourcrm.test/auth/oauth/google/callback

# --- QR cross-device login ---
LARAFOUNDRY_QR_ENABLED=true

# --- Session PIN-lock (optional Telegram-style re-entry) ---
LARAFOUNDRY_PIN_ENABLED=true

# --- Admin-failure alerts (wire Telegram later via the AdminAccessAttemptFailed event) ---
LARAFOUNDRY_NOTIFY_LOGIN_FAIL=false
```

---

## 4. The User model (existing `users` table)

### 4.1 Compose the traits

Your `App\Models\User` keeps `extends Authenticatable`; the core ships behaviour as **traits, one per phase**. For a no-companies CRM you still include `BelongsToTenancy` (its methods are inert in personal mode — see §5) and RBAC:

```php
use Dmitryisaenko\LaraFoundry\Auth\Concerns\IsLaraFoundryUser;               // identity, OAuth, sessions, PIN, avatar
use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenancy;             // tenancy API (no-op in personal mode)
use Dmitryisaenko\LaraFoundry\Authorization\Concerns\HasRolesAndPermissions; // RBAC (global roles in personal mode)
use Dmitryisaenko\LaraFoundry\Notifications\Concerns\HasNotifications;       // optional: in-app inbox
use Dmitryisaenko\LaraFoundry\Tickets\Concerns\HasTickets;                   // optional: helpdesk
use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use IsLaraFoundryUser;
    use BelongsToTenancy;
    use HasRolesAndPermissions;
    use HasNotifications;   // optional
    use HasTickets;         // optional

    public function __construct(array $attributes = [])
    {
        // The trait CONTRIBUTES these lists; it does not auto-apply them — you merge.
        $this->mergeFillable($this->laraFoundryFillable());
        $this->mergeHidden($this->laraFoundryHidden());
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            ...$this->laraFoundryCasts(),
            // ...your own casts
        ];
    }
}
```

- `IsLaraFoundryUser` already `use`s `HasApiTokens` (Sanctum), `Notifiable`, and `TwoFactorAuthenticatable` (Fortify) internally — **do not add them again**.
- It implements `HasLocalePreference` (`preferredLocale()` reads `users.locale`) and exposes `getAvatarUrlAttribute()`, `isAdmin()`, `isBlocked()`, `isDeleted()`, `isPurged()`, `isOauthOnly()`, `hasPin()`, `checkPinCode()`, `sessions()`, `recordSessionActivity()`.
- **Do not** call `Fortify::createUsersUsing(...)` etc. — the provider already binds hardened actions (`CreateNewUser`, `ResetUserPassword`, `UpdateUserPassword`, `UpdateUserProfileInformation`) over Fortify's contracts.

### 4.2 Columns added to your `users` table (coexistence)

The migration `..._add_larafoundry_columns_to_users_table.php` is **idempotent**: every column is wrapped in `if (! Schema::hasColumn('users', $col))`, so it **skips any column your own `users` migration already defines** and never overwrites your schema. Columns added (all nullable unless noted):

`lastname`, `middlename`, `phone`, `phone_verified_at`, `provider_id`, `provider_name`, `provider_token (text)`, `provider_refresh_token (text)`, `avatar`, `country`, `locale (default from config)`, `sex`, `birth_date (date)`, `ui_settings (json)`, `is_admin (bool, default false)`, `user_blocked_at`, `user_blocked_status`, `block_code (tinyint)`, `user_deleted_at`, `last_login_at`, `last_activity_at`, plus index `users_provider_index (provider_name, provider_id)`. Separate migrations add `pin_code` (1.4) and `user_purged_at` (5.3).

**Coexistence notes for an existing table:**
- The migration **unconditionally** runs `$table->string('password')->nullable()->change()` (OAuth-only users have no password). If your `password` is currently `NOT NULL`, it becomes nullable — required for Google-only accounts.
- `avatar` is a **single string column** holding a stored path, an external OAuth URL, or null; `getAvatarUrlAttribute()` resolves all three (path → media disk, URL → as-is, empty → generated initials).
- `locale` is a real column; **`theme` is NOT a column** — it lives inside `ui_settings` JSON, allow-listed in `config('larafoundry.profile.ui_settings')` (`theme`, `sidebar_collapsed`, `table_density`, `date_format`, `time_format`). Arbitrary keys are rejected (fail-closed).
- `provider_*` columns are the Socialite linkage; lookup is on `(provider_name, provider_id)`.

---

## 5. Personal mode (no companies) — what actually changes

Set once: `LARAFOUNDRY_TENANCY_MODE=personal` (config key `larafoundry.tenancy.mode`). Effects:

1. **No company routes.** `routes/tenancy.php` (company create/switch, employees, invitations) and `routes/authorization.php` (role-management UI) are **not loaded** (the provider guards on `mode !== 'personal'`). There is no company-creation wizard, no invitation flow, no company switcher to suppress — they don't register.
2. **Tenant resolver swap.** `PersonalTenantResolver` binds instead of `SessionTenantResolver`. The active tenant **is the user**.
3. **Domain models scope by `user_id` automatically.** Put `use BelongsToTenant` on each CRM domain model:
   ```php
   use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenant;
   class Customer extends Model { use BelongsToTenant; }
   ```
   `TenantScope` filters every query by `user_id` and auto-fills `user_id` on create (FAIL-CLOSED: no active tenant → zero rows on read, exception on create). Escape hatches: `Model::withoutTenancy()`, `Model::forTenant($id)`. You do **not** add `company_id`.
4. **RBAC stays, scoped globally.** Gates and `HasRolesAndPermissions` work; roles/permissions resolve with `company_id = null` (global). `larafoundry:permissions:sync` still seeds the catalog and the `authenticated` role.
5. **Tenancy shared props go null/empty.** `LaraFoundryTenancy::sharedProps()` returns `activeCompany = null`, `companies = []` — harmless to merge.
6. **Layout/shell caveat (important).** `nav_level` is `admin` for the super-admin, `tenant` only when the user has an **active company**, else `null`. In personal mode an ordinary user has no active company → the built-in `LayoutSwitcher` falls back to the bare `AppBaseLayout`, and the core menu builder returns an empty `tenant` menu. **For a single-user CRM you drive the app shell yourself** — two clean options:
   - **(A) Use your own layout** for your CRM pages: `defineOptions({ layout: YourAppLayout })`, building your sidebar from your own data; reuse core pieces (`SidebarNav`, `NotificationBell`, `LocaleSwitcher`, `UserAvatar`, the form kit) inside it.
   - **(B) Force the tenant shell:** in your `HandleInertiaRequests`, override `nav_level => 'tenant'` for signed-in non-admins and feed `navigation` from your own `MenuProvider` registered at the `tenant` level; `LayoutSwitcher` then picks `AppLayout`.

---

## 6. `bootstrap/app.php` middleware

Append the core's web middleware to the global `web` group (each is a no-op when it doesn't apply and skips its own routes, so this is safe globally):

```php
use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleAppearance;
use Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale;
use App\Http\Middleware\HandleInertiaRequests;                              // YOUR subclass (see §7)
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\TrackSessionActivity;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\EnsureAccountIsActive;
use Dmitryisaenko\LaraFoundry\Http\Middleware\RedirectSuperAdminToConsole;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\CheckPinLock;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        HandleAppearance::class,
        SetLocale::class,
        HandleInertiaRequests::class,
        TrackSessionActivity::class,
        EnsureAccountIsActive::class,
        RedirectSuperAdminToConsole::class,   // confines the super-admin to /admin
        CheckPinLock::class,                  // session PIN gate (no-op if no PIN)
    ]);

    // Sanctum stateful API for the QR verify endpoint + mobile tokens:
    $middleware->statefulApi();
})
```

Middleware **aliases** registered by the package (reference on your own routes if needed): `larafoundry.account.active`, `larafoundry.session.track`, `larafoundry.confine_admin`, `larafoundry.pin`, `larafoundry.terms`, `larafoundry.admin` (super-admin gate), `larafoundry.admin.otp` (OTP step-up), `larafoundry.tenant.set`, `larafoundry.tenant.required`.

> "Log out other devices" works only on the `database` session driver — set `SESSION_DRIVER=database` for remote-session eviction.

---

## 7. Inertia shared props (extend the core middleware)

The core's `HandleInertiaRequests` shares **only infrastructure**. Subclass it and merge the host-facing prop helpers:

```php
namespace App\Http\Middleware;

use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests as CoreHandleInertiaRequests;
use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;
use Dmitryisaenko\LaraFoundry\Authorization\LaraFoundryAuthorization;
use Dmitryisaenko\LaraFoundry\Navigation\LaraFoundryNavigation;
use Illuminate\Http\Request;

class HandleInertiaRequests extends CoreHandleInertiaRequests
{
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            ...LaraFoundryTenancy::sharedProps(),       // activeCompany(null), companies([]) — inert in personal mode
            ...LaraFoundryAuthorization::sharedProps(), // permissions: string[]
            ...LaraFoundryNavigation::sharedProps(),    // navigation, nav_level, visitor_status, impersonating
            'auth' => fn () => $request->user(),
            // ...your own CRM props
        ];
    }
}
```

**Props shared by the CORE middleware:**

| Prop | Shape | Purpose |
|---|---|---|
| `flash` | `{info,error,status,disappear_info,disappear_error}` | one-shot flash → `AppFlashMessage` |
| `locale` | `string` | active locale |
| `available_locales` | `[{code,native,flag}]` | language switcher |
| `translations` | `object` | vue-i18n bag (core `lang/frontend/*` ∪ host `lang/*.json` ∪ host `lang/<loc>/*.php`) |
| `ziggy` | Ziggy routes + `location` | `route()` on the client |
| `appearance` | `system\|light\|dark` | from cookie |
| `ui_settings` | per-user allow-listed map or `null` | `theme`, `table_density`, `date_format`, `time_format`, … |
| `auth_presentation` | `page\|modal` | how auth screens render |
| `auth_qr` | `{enabled, poll_interval_ms}` | QR tab on Login |
| `auth_oauth` | `{enabled, providers[]}` | one OAuth button per provider |
| `consent` | `{cookie:{enabled,decided}, terms_required}` | cookie banner + terms checkbox |

**Props from the host-facing helpers you merge:**

| Helper | Adds | Notes |
|---|---|---|
| `LaraFoundryTenancy::sharedProps()` | `activeCompany`, `companies` | both null/empty in personal mode |
| `LaraFoundryAuthorization::sharedProps()` | `permissions` (flat `string[]`) | membership-test in the UI |
| `LaraFoundryNavigation::sharedProps()` | `navigation`, `nav_level`, `visitor_status`, `impersonating` | server-filtered menu + shell selector |

---

## 8. Login-only (disable registration)

Registration belongs to **Fortify**, so the hard switch is in the host's `config/fortify.php`:

```php
'features' => [
    // Features::registration(),                 // ← OMIT to disable self-registration
    Features::resetPasswords(),
    Features::emailVerification(),
    Features::updatePasswords(),
    Features::updateProfileInformation(),
    Features::twoFactorAuthentication(['confirm' => true]),  // REQUIRED for the super-admin OTP gate
],
'views' => true,   // keep true: Fortify registers GET routes, the core renders them via Inertia
```

In `App\Providers\FortifyServiceProvider::boot()`:

```php
use Dmitryisaenko\LaraFoundry\Auth\LaraFoundryAuth;
LaraFoundryAuth::registerFortifyViews();   // points Fortify's view routes at the core's published Inertia auth pages
```

- Dropping `Features::registration()` removes the `/register` route entirely.
- There is also a runtime soft-switch — the app-scoped setting `signups_enabled` (`config('larafoundry.settings')`, super-admin editable) — but it does not by itself unregister the Fortify route; omitting the feature is the real off switch.
- **How users get in (no companies, no self-registration):** the operator creates them in the **core operator console** — `Admin/Users/Create.vue` at route `admin.users.create` — or you seed them. (The company-invitation path does not exist in personal mode.)
- `CreateNewUser` reserves the super-admin email (it can never be registered) and enforces the Terms checkbox when a published Terms version requires it.

---

## 9. Google OAuth

Config block `larafoundry.auth.oauth` (default `providers` is `['google','facebook','twitter']`). For **Google only**, publish `config/larafoundry.php` and set:

```php
'oauth' => [
    'enabled' => env('LARAFOUNDRY_OAUTH_ENABLED', false),
    'providers' => ['google'],            // ← Google only
    'link_existing' => false,             // anti-takeover: do NOT auto-link an OAuth email to an existing local account
    'redirect_after_login' => '/',
    'community_drivers' => [ /* apple/microsoft etc. — leave empty for Google */ ],
],
```

Host `config/services.php`:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_CLIENT_REDIRECT'),
],
```

- Routes are registered by the package (`routes/auth.php`): `GET auth/oauth/{provider}` (`larafoundry.oauth.redirect`) and `GET auth/oauth/{provider}/callback` (`larafoundry.oauth.callback`). Set the Google console redirect URI to `https://<host>/auth/oauth/google/callback`.
- The Login page renders a Google button automatically from the `auth_oauth` shared prop.
- **Security:** with `link_existing=false` the callback refuses to bind a Google identity to a pre-existing local email (account-takeover guard); the super-admin email is reserved and cannot be claimed via OAuth.

---

## 10. QR cross-device login

Config `larafoundry.auth.qr` (`enabled`, `ttl_minutes=5`, `absolute_ttl_minutes=15`, `size=400`, `poll_interval_ms=2000`).

Flow: the **guest web page** generates a QR (`POST larafoundry/qr/generate`) and polls (`POST larafoundry/qr/poll`); an **already-authenticated device** scans it and approves via `GET /api/larafoundry/qr/verify/{id}/{token}` (`auth:sanctum`, throttled). On approval the web session logs in. Model: `SignInRequest` (token stored as a SHA-256 hash, sliding + absolute TTL).

**Requirements / constraints:**
- **HTTPS is mandatory in production** (the QR encodes a token). It cannot be exercised on plain-HTTP localhost.
- **Sanctum must be configured** (`statefulApi()` in `bootstrap/app.php`, §6).
- **The super-admin cannot approve a QR login** (403) — deliberate, so the QR path can't bypass the operator OTP step-up.
- The **scanner component is not in the barrel** (it lazy-loads the heavy `html5-qrcode`). The scanning-device page imports it directly and adds the dep:
  ```js
  import QrScanner from '@dmitryisaenko/larafoundry/resources/js/components/auth/QrScanner.vue';
  ```
  ```bash
  npm i html5-qrcode
  ```
  The Login page's `QrLoginPanel` (generation + polling) **is** in the barrel.

---

## 11. Super-admin = the host email

A user is the super-admin when **`users.is_admin = true` AND their email equals `larafoundry.security.super_admin.email`** (defence-in-depth — when the email is configured, the flag alone is not enough). Setup:

1. Set `LARAFOUNDRY_SUPER_ADMIN_EMAIL`. That email is **reserved** — it cannot self-register and cannot own a tenant.
2. **Seed the operator user** with `is_admin = 1` and that exact email (`is_admin` is not mass-assignable — set it explicitly):
   ```php
   User::create([...])->forceFill(['is_admin' => true])->save();
   ```
3. **OTP step-up is on by default** (`require_otp=true`). The operator must enrol TOTP 2FA; point `LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE` at your 2FA-enrolment screen's route name, **or the gate denies with 403** (fail-closed). Every entry to `/admin/*` requires a fresh OTP once per session (`EnsureAdminOtpVerified`); a fresh login or logout drops the flag, so OAuth logins are still challenged before the console opens.
4. `RedirectSuperAdminToConsole` keeps the operator confined to `console_route` (`admin.dashboard.index`) and the `allowed_routes` fnmatch list.

> Wire a Telegram/Slack alert for failed operator logins by listening to `Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed` and appending your channel to `larafoundry.auth.failed_login.channels` — no core change.

---

## 12. Frontend wiring (the #1 integration risk)

The published Vue pages import from the bare specifier **`@dmitryisaenko/larafoundry`**, but the package has **no `package.json`/npm publish** — that specifier is resolved by a **Vite alias** to the package's JS barrel `resources/js/index.js` (which exports `createLaraFoundry`, every component, the layouts, `dashboardWidgets`, `registerDashboardWidget`, the i18n + `useDateFormat`/`formatDate` helpers).

**`vite.config.js`:**

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [laravel({ input: ['resources/js/app.js'], refresh: true }), vue()],
    resolve: {
        alias: {
            '@dmitryisaenko/larafoundry': '/vendor/dmitryisaenko/larafoundry/resources/js/index.js',
            '@dmitryisaenko/larafoundry/resources': '/vendor/dmitryisaenko/larafoundry/resources', // subpath imports (QrScanner)
        },
    },
});
```

**`resources/js/app.js`:**

```js
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';                          // Ziggy + Inertia plugin are YOUR responsibility
import { createLaraFoundry } from '@dmitryisaenko/larafoundry';

createInertiaApp({
    // Pages were published into resources/js/Pages by `vendor:publish --tag=larafoundry-pages`:
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) }).use(plugin).use(ZiggyVue);
        createLaraFoundry(app, props.initialPage.props);       // installs vue-i18n ($t global) + shared components
        app.mount(el);
    },
});
```

**`resources/css/app.css`:**

```css
@import 'tailwindcss';
@import '../../vendor/dmitryisaenko/larafoundry/resources/css/theme.css';   /* Tailwind v4 @theme design tokens */
```

> The pages are **published** (copied) into `resources/js/Pages`, so your `import.meta.glob` already sees them — they're not loaded from `vendor/`. Only the **shared library** (the barrel) and **theme.css** are referenced through `vendor/` via the alias. Re-run `vendor:publish --tag=larafoundry-pages` and rebuild after a core upgrade that touches UI.

**npm peers:** `@inertiajs/vue3`, `vue`, `vue-i18n`, `ziggy-js`, `@vitejs/plugin-vue`, `laravel-vite-plugin`, `tailwindcss@^4` (+ `@tailwindcss/vite` or the postcss plugin), and `html5-qrcode` **only** if you build the QR scanner page.

---

## 13. Vue layouts & the operator-console seam (host "Statistics / Logs")

**Layouts** (exported from the barrel): `AppBaseLayout` (bare), `AppLayout` (tenant shell), `AdminLayout` (operator console), `LayoutSwitcher` (persistent layout that picks by `nav_level`). A page sets its shell with `defineOptions({ layout: AdminLayout })` (or `LayoutSwitcher`).

**Adding your own admin pages into the core console** (the "Statistics / Logs" example) — the same additive seam the core uses:

1. **Routes** — in your `routes/web.php`, behind the same gates as the console:
   ```php
   Route::middleware(['web','auth','verified','larafoundry.admin','larafoundry.admin.otp'])
       ->prefix('admin/stats')->name('admin.stats.')
       ->group(fn () => Route::get('/', [StatsController::class, 'index'])->name('index'));
   ```
2. **Menu item** — implement `Navigation\Contracts\MenuProviderInterface`, return a `MenuItem` for `level === 'admin'`, register it in a host provider's `boot()`:
   ```php
   $this->app->make(\Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder::class)
       ->addProvider($this->app->make(\App\Navigation\StatsMenuProvider::class));
   ```
   `MenuItem(labelKey, route, policy, icon, order, ...)` — `labelKey` is an i18n key, `policy` an RBAC slug (server-filtered), `icon` resolved by `NavIcon`.
3. **Page** — your `resources/js/Pages/Admin/Stats/Index.vue` with `defineOptions({ layout: AdminLayout })`.
4. **Dashboard widgets** (optional) — implement `Dashboard\Contracts\DashboardWidgetProviderInterface`, register on `DashboardBuilder`, and on the frontend `registerDashboardWidget('StatsWidget', StatsWidget)` (from the barrel) before mount.

The core admin console already provides: Dashboard, Users (CRUD + impersonation), Companies (hidden/empty in personal mode), Activity log, Broadcasts, Support tickets, Email templates, Legal pages, Settings — all under `/admin`, gated by `larafoundry.admin` + `larafoundry.admin.otp`.

---

## 14. Sanctum (mobile) + Cashier (billing) coexistence

- **Sanctum:** `IsLaraFoundryUser` already includes `HasApiTokens`. Issue personal access tokens for the mobile app as usual (`$user->createToken(...)`). The QR verify endpoint already runs on `auth:sanctum`. Your own `routes/api.php` is yours to add; keep `statefulApi()` for cookie-based SPA calls.
- **Cashier:** the core's billing is a **seam only** (`billing.enabled=false` → `Company::hasAccess()` always true). Billing lives on the **Company** model (trait `HasSubscription`), **not** on `User`. In personal mode there are no companies, so the core billing gate is inert. For per-user paid plans, add Cashier's `Billable` to your `User` yourself — it does not conflict with the core traits. (The paid `dmitryisaenko/larafoundry-billing` add-on targets the company model and the `teams` flow.)

---

## 15. Quick gotcha checklist

- [ ] Frontend is **Inertia + Vue 3** (not Blade/React) — required to consume the pages.
- [ ] **Vite alias** `@dmitryisaenko/larafoundry` → `vendor/.../resources/js/index.js` (the make-or-break step).
- [ ] `import.meta.glob('./Pages/**/*.vue')` over the **published** `resources/js/Pages`.
- [ ] `theme.css` imported from `vendor/`.
- [ ] `LARAFOUNDRY_TENANCY_MODE=personal`; domain models `use BelongsToTenant` (auto `user_id` scope); drive your own app shell (§5.6).
- [ ] Registration disabled by **omitting `Features::registration()`**; keep `Features::twoFactorAuthentication()`.
- [ ] Super-admin user has **both** `is_admin=true` **and** the configured email; `LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE` set, or the OTP gate 403s.
- [ ] `oauth.providers = ['google']`, creds in `config/services.php`, redirect URI registered with Google.
- [ ] QR needs **HTTPS** + Sanctum; scanner page imports `QrScanner` directly + `npm i html5-qrcode`.
- [ ] `password` becomes nullable (OAuth-only users) — fine for an existing table.
- [ ] `SESSION_DRIVER=database` if you want "log out other devices".
- [ ] After a core upgrade that ships UI: re-run `vendor:publish --tag=larafoundry-pages` and rebuild.

---

*Verify §2 (tags), §4.2 (columns), §7 (shared props) and §12 (frontend wiring) against the version of the package you have installed if behaviour differs.*
