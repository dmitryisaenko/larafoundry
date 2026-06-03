# LaraFoundry

> A reusable SaaS/CRM core for Laravel, extracted in public from a production system.

LaraFoundry is a modular SaaS foundation being extracted from [Kohana.io](https://kohana.io), a real production CRM/ERP. The goal is to package the cross-cutting parts every SaaS rebuilds from scratch (auth, multi-tenancy, i18n, admin, billing) as a clean, tested Composer package, so you don't write them again.

This is built **in public** and **by extraction, not rewrite**. Each piece is pulled from battle-tested production code, modernized, hardened, covered with Pest, reviewed, and only then tagged. The README tracks what is *actually in the package*, not what is planned. See the roadmap for what's coming.

**Tech stack:** Laravel 12 / 13, PHP 8.2+, Inertia 2 / 3, Vue 3, Tailwind CSS 4, Ziggy, Pest. Authentication builds on [Laravel Fortify](https://laravel.com/docs/fortify) and [Socialite](https://laravel.com/docs/socialite); the activity log builds on [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog).

```bash
composer require dmitryisaenko/larafoundry
```

> ⚠️ **Status: early but growing. Current release is `v0.7.x`: foundation, authentication, multi-tenancy, RBAC, the platform activity log, multilanguage, and the navigation engine + first operator-console screen (Admin Users + impersonation).**
> Billing, admin companies / dashboard, notifications and the other domain modules are not in the package yet; they are being extracted phase by phase. Domain permissions, domain events and the host's own menu items are deliberately the host's job, not the core's. Don't `composer require` this expecting a finished SaaS engine. Expect a hardened set of primitives those modules stand on.

---

## What's in the package

### `v0.7.x` Navigation engine + operator console (Admin Users)

A permission-aware navigation engine, and the first real screen of the operator console built on top of it. The menu is built **and filtered on the backend**, so links a user may not follow never reach the browser.

| Component | What it does |
|-----------|--------------|
| `MenuItem` + `MenuBuilder` + `MenuProviderInterface` | The engine. Providers contribute `MenuItem`s for a level (`admin` / `tenant`); the builder merges them, filters by RBAC (and `visible`), sorts by `order`, and emits the tree already pruned. Labels are i18n **keys**, translated in Vue, so a language switch re-paints the menu without a reload. Icons are names resolved to inline SVG (no published assets). The core ships an admin menu (Users, Activity log) and a tenant menu (Employees, Roles); a host adds its own via a provider. |
| `RbacPolicyChecker` | Bridges menu visibility to `hasPermissionTo($slug, $activeCompany)` (the same rule that guards the routes), and fails closed. |
| `LayoutSwitcher` + `AppLayout` | A persistent layout that picks the shell from a single backend signal (`nav_level`): super-admin gets the operator console, a tenant member gets the app shell with the filtered sidebar, everyone else gets the bare base shell. `MobileNav` reuses the same tree in a drawer. |
| Admin Users console | `Admin/Users/{Index,Edit,Create}` behind `larafoundry.admin`: list with filters (search / status / verification) + pagination, create / edit, block / unblock, soft-delete / restore. Blocking also invalidates the user's tracked sessions; every action is written to the activity log. Privilege/state columns are never mass-assigned. The resource omits social links (PII). |
| Impersonation | "Follow into a user", super-admin only. The policy refuses impersonating another admin, a blocked/deleted account, or yourself; take and leave are both audited and the session id is rotated on each identity swap. `leave` lives outside the admin gate (while impersonating you are not an admin). |

> Admin Companies and the admin Dashboard are intentionally **not** here: they are ~80% billing data, so they ship with the billing phase to avoid a double extract.

### `v0.6.x` Multilanguage (i18n)

The language layer on top of the `v0.1.0` locale foundation: a way to switch language, and the core's own screens translated out of the box. The core ships **English and Ukrainian**; adding more locales is the host's job (the world's languages are not the core's to maintain).

| Component | What it does |
|-----------|--------------|
| `LanguageController` + switch route | A `POST` switch route (`larafoundry.language.switch`, CSRF-protected, open to guests and signed-in users). The submitted code is validated against the locale allow-list, then persisted: session + a year-long cookie for everyone, plus the stored DB preference when signed in (the authoritative source `SetLocale` reads back first, so the choice never bounces). The redirect back is constrained to the app's own host, so a forged `Referer` can't turn it into an open redirect. |
| `LocaleSwitcher` Vue component | A dropdown driven by the shared `available_locales` prop (each code with its native name and flag). Renders nothing when only one locale is available, so it's safe to drop into any layout. |
| Bundled translations | Server-side `larafoundry::` strings (mail, flash, geo) in English and Ukrainian, plus a frontend dictionary for the core's Inertia pages. A host overrides any core string from its own `lang/{locale}.json`; the core dictionary sits underneath as the default. |

> The locale resolution chain, the `ValidLocale` allow-list and the `HasLocalePreference` contract are the `v0.1.0` foundation; this phase adds the user-facing switch and the second language on top of them.

### `v0.5.x` Activity log (platform audit)

A platform-operator audit log. This is a **super-admin tool, not a tenant feature**: a platform operator reads it globally, so the log carries no `company_id`. It also lands the first slice of the operator console.

| Component | What it does |
|-----------|--------------|
| `Activity` model | Extends spatie's Activity with HTTP / device / geo context columns and is bound as spatie's `activity_model`, so the package's own helpers and the model-audit trait write the core's columns too. |
| Config event registry | `config/larafoundry-activitylog.php` maps event classes to a group / description / code. The core registers its own auth + tenancy events; the host adds domain events with the same additive-merge pattern as the permissions catalog. |
| `ActivityLogService` (+ `Activity` facade) | Records entries with a distinct **causer** (the actor) and **subject** (the object acted on), never the same id in both, plus PII redaction of query-string secrets and property keys before storage. |
| `LogsActivity` trait | Opt-in model auditing on top of spatie: real created / updated / deleted diffs decorated with the core's device / IP / route context. |
| Geo enrichment | A swappable `GeoResolver` (default ip-api.com) run **asynchronously** off a queued job, **opt-out** via config; private / loopback IPs are answered locally and never sent out. |
| `EnsureSuperAdmin` middleware (`larafoundry.admin`) | Gates the operator console on the core's `VisitorStatus`, with an optional IP allow-list. |
| Super-admin viewer | `Admin/Logs/GeneralLogs` and `UserLogs` Inertia pages on a minimal `AdminLayout`, behind the gate: global and per-user views, an hours filter, success / error badges, expandable device / geo / request detail. All user-controlled fields render as text (no `v-html`). |

> The optional route-access middleware (`larafoundry.activity.route`) logs every wrapped request and is **off by default** (noisy). Retention is driven by `retention_days`, pruned by spatie's `activitylog:clean`, which the host schedules.

### `v0.4.x` Roles & permissions (RBAC)

Tenant-scoped RBAC, **self-written, not** `spatie/laravel-permission`, because every role assignment and permission grant is scoped to a company from day one. The same user can be an admin in one company and read-only in another.

| Component | What it does |
|-----------|--------------|
| `HasRolesAndPermissions` trait | Permission checks in a strict priority order: super-admin bypass, then company-owner bypass, then the resolved set (company roles + global roles + individual grants, minus revokes), memoized per request. |
| Catalog + `larafoundry:permissions:sync` | Permissions, global roles and role templates declared in `config/larafoundry-permissions.php` and upserted idempotently. The core ships only its own permissions and one neutral starter role; domain permissions are the host's. |
| Clone-on-create | A queued, idempotent listener on `CompanyCreated` clones the template roles into every new company. |
| Role management | `RoleController` + `EmployeeAccessController` with a holder-check (you can only grant or assign what you already hold) and structural anti-IDOR scoping, plus the `Roles` Vue pages and a `PermissionsSelector`. |

> Super-admin is an identity flag resolved through `VisitorStatus`, never a role, so it can't be granted from a role-management screen.

### `v0.3.x` Multi-tenancy (companies / teams)

| Component | What it does |
|-----------|--------------|
| `BelongsToTenancy` (User) / `BelongsToTenant` (domain models) | Companies, ownership and membership on the user; an automatic, **fail-closed** tenant scope on domain models (no resolved tenant means no rows, never all rows). |
| `TenantScope` + resolvers | Session-based resolver for `teams` mode (active company tracked on the session row) and a `personal` mode where the user is their own tenant, behind one `TenantResolver` contract. |
| Company creation wizard | Multi-step company setup (no billing), `CompanySwitcher`, and the `SetActiveTenant` / `EnsureActiveTenant` middleware. |
| Invitations | Token invitations with a verified-email join guard, expiry, and IDOR-safe resend / delete scoped to the active company. |

### `v0.2.x` Authentication + Users

Authentication built on top of Laravel Fortify (the official, headless auth backend), with the pieces Fortify does not cover added around it.

| Component | What it does |
|-----------|--------------|
| `IsLaraFoundryUser` trait | Identity slice for the host's User model: name parts, phone, avatar, locale, OAuth provider linkage, blocking state, per-user 2FA (`TwoFactorAuthenticatable`), session tracking. Adds nothing about companies or roles; those arrive as their own traits in later phases. |
| OAuth (`OAuthController`) | Social sign-in via Socialite. Resolves strictly by provider identity first, then email, with an account-takeover guard: an OAuth login whose email matches an existing local account is refused by default rather than silently linked. |
| Login pipeline + Fortify actions | Hardened `CreateNewUser` / `ResetUserPassword` / `UpdateUserPassword` bound over Fortify's contracts, with a password policy stronger than the donor's. |
| `TrackSessionActivity` middleware | Records one tracked session row per device (fingerprint, IP, login method, last activity, last route) on every authenticated request. Powers an "active sessions" view and "log out other devices". |
| `EnsureAccountIsActive` middleware | Per-request gate that logs out blocked or soft-deleted accounts. |
| `VisitorStatus` | Identity-level status resolver (guest / authenticated / verified / blocked / deleted / admin) with a defence-in-depth admin check. |
| Localized auth mail | Verification and reset mail wording is owned by the core through `larafoundry::auth` translations, so it ships localized and follows the locale standard. Hosts override text and layout via publish. |
| Inertia + Vue auth pages | Login, Register, ForgotPassword, ResetPassword, VerifyEmail, ConfirmPassword, TwoFactorChallenge, TwoFactorSettings, UserBlocked, built on the form UI kit. Published into the host and rendered through Fortify's view resolvers. |

Two-factor (TOTP + recovery codes + QR enrolment) and passkeys come from Fortify out of the box.

### `v0.1.0` Foundation layer

The cross-cutting primitives every later module depends on.

| Component | What it does |
|-----------|--------------|
| `SetLocale` middleware | One resolution chain (user preference, session, cookie, `Accept-Language`, optional geo-IP, default). Every source is validated against a single allow-list before it is applied, so no junk locale codes reach the app or the DB. |
| `ValidLocale` rule | Validation rule backing the same single source of truth for locales. |
| `HandleInertiaRequests` | Base Inertia middleware sharing flash, active locale, the translation bag, Ziggy and appearance. Host apps extend it and merge their own props. |
| `Filter` + `Filterable` | Query-filter base: one method per request parameter. Hardened against mass-method-invocation, so only public methods declared on the concrete subclass are callable from request input. |
| `EnsureEmailIsVerified` | Email-verification gate with a config-driven allow-list of routes/prefixes and a `shouldBypass()` hook for host-specific overrides. |
| `RestrictAuthByIp` | IP allow-list for the admin/auth zone in production. |
| `StoreIntendedUrl` | Captures full-page Inertia visits as the post-login redirect target. |
| `HandleAppearance` | Light/dark/system preference, read from a cookie, shared to views. |
| `HasPagination` | Normalizes any paginator into a flat Inertia-friendly payload. |

**Frontend (Inertia + Vue 3 + Tailwind 4):**

- **`createLaraFoundry(app, pageProps)`** is the single bootstrap call. It installs vue-i18n wired from the backend's shared props (`{{ $t('key') }}` works in any template, no import) and registers the shared components.
- **Form UI kit:** `InputField`, `TextareaField`, `SelectField`, `DateField` with inline validation errors.
- **`AppFlashMessage`** for toast notifications driven by the flash contract.
- **`PagePaginator`** consuming the `HasPagination` payload.
- **`LocaleSwitcher` / `CompanySwitcher`** dropdowns wired to the shared locale and company props.
- **`AuthCard` / `AppBaseLayout`** layout primitives.
- **`theme.css`** with Tailwind v4 `@theme` design tokens, importable straight from `vendor/`.

---

## Installation

```bash
composer require dmitryisaenko/larafoundry
```

The service provider auto-registers (config merge, routes, migrations, console commands, middleware aliases). Run the installer, migrate, and seed the permission catalog:

```bash
php artisan larafoundry:install        # publishes config, seeds the catalog
php artisan migrate
php artisan larafoundry:permissions:sync
```

**Publishable tags** (publish what you want to override; all are optional):

| Tag | Publishes |
|-----|-----------|
| `larafoundry-config` | `config/larafoundry.php` (core: locale, tenancy, security, auth) |
| `larafoundry-permissions` | `config/larafoundry-permissions.php` (RBAC catalog to extend) |
| `larafoundry-activitylog` | `config/larafoundry-activitylog.php` (event registry, geo, retention, PII keys) |
| `larafoundry-pages` | the Inertia + Vue pages into `resources/js/Pages` |
| `larafoundry-lang` | translation files into `lang/vendor/larafoundry` |

> Publish the Vue pages whenever you change a phase that ships UI (auth, tenancy, RBAC, activity log) and rebuild your frontend.

### Authentication setup

Authentication pulls in Laravel Fortify. Install it and point its headless views at the core's published pages.

```bash
php artisan fortify:install
php artisan vendor:publish --tag=larafoundry-pages
```

In `config/fortify.php` keep `'views' => true` (Fortify then registers the GET routes and the core renders them through Inertia) and enable the features you want, including `Features::twoFactorAuthentication(['confirm' => true])`.

In your `App\Providers\FortifyServiceProvider::boot()`:

```php
use Dmitryisaenko\LaraFoundry\Auth\LaraFoundryAuth;

// Point Fortify's view routes at the core's published Inertia auth pages.
LaraFoundryAuth::registerFortifyViews();
```

The core already binds the hardened `CreateNewUser` / `ResetUserPassword` / `UpdateUserPassword` actions over Fortify's contracts, so do not call `Fortify::createUsersUsing(...)` and friends in the host. That would re-introduce Fortify's scaffolded actions.

Compose the core onto your User model, one trait per phase you enable:

```php
use Dmitryisaenko\LaraFoundry\Auth\Concerns\IsLaraFoundryUser;            // 1.1 auth/identity
use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenancy;          // 1.2 companies/teams
use Dmitryisaenko\LaraFoundry\Authorization\Concerns\HasRolesAndPermissions; // 1.3 RBAC
use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use IsLaraFoundryUser;
    use BelongsToTenancy;
    use HasRolesAndPermissions;

    public function __construct(array $attributes = [])
    {
        $this->mergeFillable($this->laraFoundryFillable());
        $this->mergeHidden($this->laraFoundryHidden());
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return $this->laraFoundryCasts();
    }
}
```

The activity log needs no trait on User: it resolves the causer automatically. Add the optional `LogsActivity` trait to any model you want audited.

### Wiring the middleware (host `bootstrap/app.php`)

```php
use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleAppearance;
use Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\TrackSessionActivity;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleInertiaRequests; // extends the core one

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        HandleAppearance::class,
        SetLocale::class,
        HandleInertiaRequests::class,
        TrackSessionActivity::class,
        EnsureAccountIsActive::class,
    ]);
})
```

> "Log out other devices" evicts remote sessions immediately on the `database` session driver. On other drivers the framework session lives outside the package's reach, so that feature needs the database driver.

### Extending the Inertia middleware

```php
use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests as CoreHandleInertiaRequests;
use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;
use Dmitryisaenko\LaraFoundry\Authorization\LaraFoundryAuthorization;
use Dmitryisaenko\LaraFoundry\Navigation\LaraFoundryNavigation;

class HandleInertiaRequests extends CoreHandleInertiaRequests
{
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            ...LaraFoundryTenancy::sharedProps(),       // activeCompany + companies (CompanySwitcher)
            ...LaraFoundryAuthorization::sharedProps(), // the user's permission map
            ...LaraFoundryNavigation::sharedProps(),    // navigation tree + nav_level (LayoutSwitcher)
            'auth' => fn () => $request->user(),
            // your own props
        ];
    }
}
```

> The tenancy and authorization shared props are required for the `CompanySwitcher` and the permission-aware UI to receive their data; the navigation props feed the sidebar and the `LayoutSwitcher`. Omit them and those components render empty.

#### Adding your own menu items (host menu provider)

The core only ships its own screens in the menu. To add yours, implement `MenuProviderInterface` and register it on the shared `MenuBuilder` (e.g. in a service provider's `boot`):

```php
use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

class OrdersMenuProvider implements MenuProviderInterface
{
    public function getMenuItems(string $level): array
    {
        return $level === 'tenant' ? [
            new MenuItem(labelKey: 'Orders', route: 'orders.index', policy: 'orders.view', icon: 'orders', order: 50),
        ] : [];
    }

    public function supports(string $level): bool { return $level === 'tenant'; }

    public function priority(): int { return 50; }
}

// In a host service provider's boot():
$this->app->make(MenuBuilder::class)->addProvider($this->app->make(OrdersMenuProvider::class));
```

> Labels are i18n keys (translated in Vue), `policy` is an RBAC permission slug the builder filters on, and `icon` is a name your `NavIcon` set resolves. The builder filters server-side, so an item the user lacks the permission for is never sent to the browser.

### Frontend bootstrap (host `app.js`)

```js
import { createLaraFoundry } from '@dmitryisaenko/larafoundry';

createInertiaApp({
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) }).use(plugin);
        createLaraFoundry(app, props.initialPage.props);
        app.mount(el);
    },
});
```

```css
/* host app.css */
@import 'tailwindcss';
@import '../../vendor/dmitryisaenko/larafoundry/resources/css/theme.css';
```

---

## Roadmap

LaraFoundry is extracted phase by phase. Domain modules below are **planned**, being lifted from the production source, not yet shipped. Module docs describe the production implementation they are extracted from; package APIs may differ as they are modernized.

| Phase | Area | Status |
|-------|------|--------|
| 0.x | Foundation primitives (locale, filters, middleware, UI kit) | ✅ Shipped (`v0.1.0`) |
| 1.1 | [Authentication](docs/modules/authentication.md) & [Users / Registration](docs/modules/registration.md) | ✅ Shipped (`v0.2.x`) |
| 1.2 | [Multi-tenancy](docs/modules/multi_tenancy.md) (companies / teams) | ✅ Shipped (`v0.3.x`) |
| 1.3 | Roles & permissions (RBAC) | ✅ Shipped (`v0.4.x`) |
| 2.1 | [Activity logging](docs/modules/logging.md) (platform audit) | ✅ Shipped (`v0.5.x`) |
| 2.2 | [Multilanguage](docs/modules/multilanguage.md) (i18n, language switcher) | ✅ Shipped (`v0.6.x`) |
| 2.3 | [Navigation](docs/modules/navigation.md) engine + [Admin users](docs/modules/admin_users.md) console (+ impersonation) | ✅ Shipped (`v0.7.x`) |
| 3.x | [Payments](docs/modules/payments.md) / billing, [Admin companies](docs/modules/admin_companies.md) + dashboard, [Notifications](docs/modules/notifications.md), [Tickets](docs/modules/tickets.md) | 📋 Planned |

Build-in-public write-ups for each shipped phase are on [Dev.to](https://dev.to/d_isaenko_dev).

---

## Quality

- **Pest** on every piece of the core: 290 tests across foundation, auth, tenancy, RBAC, the activity log, multilanguage and the navigation/operator-console layer, many of which caught real bugs during extraction and review (a broken default-locale fallback, a mass-method-invocation gap in the filter dispatcher, a fail-open tenant scope, a privilege-escalation hole in delegated permission grants, a misrecorded audit subject, an open redirect on the language switch, and the donor's wide-open impersonation, where any admin could impersonate anyone including other admins with no audit, now policy-gated, audited and session-rotated).
- **Frontend tests** with Vitest + Vue Test Utils on the UI kit, pages and navigation components, including a stored-XSS guard on the activity-log table.
- **CI** runs Pest + Pint across PHP 8.2 / 8.3 / 8.4 plus the frontend suite on every push.
- Every module passes `/security-review` + `/code-review` before its tag.

---

## License

LaraFoundry is **source-available** and **dual-licensed**: free for non-commercial use, paid for commercial use. See [LICENSE.md](LICENSE.md) for the full terms.

---

## Author

**Dmitry Isaenko**, full-stack Laravel developer building SaaS tools.

- Website: [larafoundry.com](https://larafoundry.com)
- Dev.to: [@d_isaenko_dev](https://dev.to/d_isaenko_dev)
- LinkedIn: [Dmitry Isaenko](https://linkedin.com/in/d-isaenko-dev)
- X: [@d_isaenko_dev](https://twitter.com/d_isaenko_dev)
