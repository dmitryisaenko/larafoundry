# LaraFoundry

> A reusable SaaS/CRM core for Laravel, extracted in public from a production system.

LaraFoundry is a modular SaaS foundation being extracted from [Kohana.io](https://kohana.io), a real production CRM/ERP. The goal is to package the cross-cutting parts every SaaS rebuilds from scratch (auth, multi-tenancy, i18n, admin, billing) as a clean, tested Composer package, so you don't write them again.

This is built **in public** and **by extraction, not rewrite**. Each piece is pulled from battle-tested production code, modernized, hardened, covered with Pest, reviewed, and only then tagged. The README tracks what is *actually in the package*, not what is planned. See the roadmap for what's coming.

**Tech stack:** Laravel 12 / 13, PHP 8.2+, Inertia 2 / 3, Vue 3, Tailwind CSS 4, Ziggy, Pest. Authentication builds on [Laravel Fortify](https://laravel.com/docs/fortify) and [Socialite](https://laravel.com/docs/socialite).

```bash
composer require dmitryisaenko/larafoundry
```

> ⚠️ **Status: early. Current release is `v0.2.x`, foundation + authentication.**
> Multi-tenancy, admin, billing and the other domain modules are not in the package yet. They are being extracted phase by phase. Don't `composer require` this expecting a finished SaaS engine. Expect the primitives those modules will stand on.

---

## What's in the package

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
- **`AuthCard` / `AppBaseLayout`** layout primitives.
- **`theme.css`** with Tailwind v4 `@theme` design tokens, importable straight from `vendor/`.

---

## Installation

```bash
composer require dmitryisaenko/larafoundry
```

The service provider auto-registers (config merge, routes, migrations, console commands, middleware aliases). Publish the config and run migrations:

```bash
php artisan larafoundry:install
php artisan migrate
```

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

Add the trait to your User model:

```php
use Dmitryisaenko\LaraFoundry\Auth\Concerns\IsLaraFoundryUser;
use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use IsLaraFoundryUser;

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

class HandleInertiaRequests extends CoreHandleInertiaRequests
{
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn () => $request->user(),
            // your own props
        ];
    }
}
```

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
| 1.2 | [Multi-tenancy](docs/modules/multi_tenancy.md) & authorization | 🔜 Next |
| 2.x | [Activity logging](docs/modules/logging.md), [Navigation](docs/modules/navigation.md), [Admin users](docs/modules/admin_users.md), [Admin companies](docs/modules/admin_companies.md) | 📋 Planned |
| 3.x | [Notifications](docs/modules/notifications.md), [Tickets](docs/modules/tickets.md), [Payments](docs/modules/payments.md) | 📋 Planned |

Build-in-public write-ups for each shipped phase are on [Dev.to](https://dev.to/d_isaenko_dev).

---

## Quality

- **Pest** on every piece of the core. 114 tests across the foundation and auth layers, several of which caught real bugs during extraction (a broken default-locale fallback, a mass-method-invocation gap in the filter dispatcher, and a session-id desync between the login event and Fortify's session regeneration).
- **Frontend tests** with Vitest + Vue Test Utils on the UI kit and auth pages.
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
