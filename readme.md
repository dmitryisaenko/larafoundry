# LaraFoundry

> A reusable SaaS/CRM core for Laravel, extracted in public from a production system.

LaraFoundry is a modular SaaS foundation being extracted from [Kohana.io](https://kohana.io) - a real, production CRM/ERP. The goal is to package the cross-cutting parts every SaaS rebuilds from scratch - auth, multi-tenancy, i18n, admin, billing - as a clean, tested Composer package, so you don't write them again.

This is built **in public** and **by extraction, not rewrite**: each piece is pulled from battle-tested production code, modernized, hardened, covered with Pest, reviewed, and only then tagged. The README tracks what is *actually in the package*, not what is planned - see the roadmap for what's coming.

**Tech stack:** Laravel 12 / 13 · PHP 8.2+ · Inertia 2 / 3 · Vue 3 · Tailwind CSS 4 · Ziggy · Pest

```bash
composer require dmitryisaenko/larafoundry
```

> ⚠️ **Status: early. Current release is `v0.1.0` - the foundation layer.**
> Auth, multi-tenancy, admin, billing and the other domain modules are not in the package yet; they are being extracted phase by phase. Don't `composer require` this expecting a finished SaaS engine - expect the primitives those modules will stand on.

---

## What's in `v0.1.0`

The foundation layer: the cross-cutting primitives every later module depends on. 14 PHP classes, a small Inertia/Vue frontend, **39 Pest tests**, green CI on PHP 8.2 / 8.3 / 8.4.

### Backend

| Component | What it does |
|-----------|--------------|
| `SetLocale` middleware | One resolution chain (user preference → session → cookie → `Accept-Language` → optional geo-IP → default). **Every** source validated against a single allow-list before it's applied - no junk locale codes can reach the app or the DB. |
| `ValidLocale` rule | Validation rule backing the same single source of truth for locales. |
| `HandleInertiaRequests` | Base Inertia middleware sharing flash, active locale, the translation bag, Ziggy and appearance. Host apps extend it and merge their own props. |
| `Filter` + `Filterable` | Query-filter base: one method per request parameter. Hardened against mass-method-invocation - only public methods declared on the concrete subclass are callable from request input. |
| `EnsureEmailIsVerified` | Email-verification gate with a config-driven allow-list of routes/prefixes and a `shouldBypass()` hook for host-specific overrides. |
| `RestrictAuthByIp` | IP allow-list for the admin/auth zone in production. |
| `StoreIntendedUrl` | Captures full-page Inertia visits as the post-login redirect target. |
| `HandleAppearance` | Light/dark/system preference, read from a cookie, shared to views. |
| `HasPagination` | Normalizes any paginator into a flat Inertia-friendly payload. |

### Frontend (Inertia + Vue 3 + Tailwind 4)

- **`createLaraFoundry(app, pageProps)`** - single bootstrap call. Installs vue-i18n wired from the backend's shared props (`{{ $t('key') }}` works in any template, no import) and registers the shared components.
- **Form UI kit** - `InputField`, `TextareaField`, `SelectField`, `DateField` with inline validation errors.
- **`AppFlashMessage`** - toast notifications driven by the flash contract.
- **`PagePaginator`** - page-number paginator consuming the `HasPagination` payload.
- **`AppBaseLayout`** - minimal base layout.
- **`theme.css`** - Tailwind v4 `@theme` design tokens, importable straight from `vendor/`.

---

## Installation

Add the package:

```bash
composer require dmitryisaenko/larafoundry
```

The service provider auto-registers (config merge, routes, migrations, console commands). Publish the config:

```bash
php artisan larafoundry:install
```

### Wiring the middleware (host `bootstrap/app.php`)

```php
use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleAppearance;
use Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale;
use App\Http\Middleware\HandleInertiaRequests; // extends the core one

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        HandleAppearance::class,
        SetLocale::class,
        HandleInertiaRequests::class,
    ]);
})
```

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
            // your own props…
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

LaraFoundry is extracted phase by phase. Domain modules below are **planned**, being lifted from the production source - not yet shipped in the package. Module docs describe the production implementation they're extracted from; package APIs may differ as they're modernized.

| Phase | Area | Status |
|-------|------|--------|
| 0.x | Foundation primitives (locale, filters, middleware, UI kit) | ✅ Shipped (`v0.1.0`) |
| 1.x | [Authentication](docs/modules/authentication.md) & [Users / Registration](docs/modules/registration.md) | 🔜 Next |
| 2.x | [Multi-tenancy](docs/modules/multi_tenancy.md) & authorization | 📋 Planned |
| - | [Activity logging](docs/modules/logging.md) · [Navigation](docs/modules/navigation.md) · [Admin users](docs/modules/admin_users.md) · [Admin companies](docs/modules/admin_companies.md) | 📋 Planned |
| - | [Notifications](docs/modules/notifications.md) · [Tickets](docs/modules/tickets.md) · [Payments](docs/modules/payments.md) | 📋 Planned |

Build-in-public write-ups for each shipped phase are on [Dev.to](https://dev.to/d_isaenko_dev).

---

## Quality

- **Pest** on every piece of the core. 39 tests in `v0.1.0` (two of them caught real bugs during extraction: a broken default-locale fallback and a mass-method-invocation gap in the filter dispatcher).
- **CI** runs Pest + Pint across PHP 8.2 / 8.3 / 8.4 on every push.
- Every module passes `/security-review` + `/code-review` before its tag.

---

## License

LaraFoundry is **source-available** and **dual-licensed**: free for non-commercial use, paid for commercial use. See [LICENSE.md](LICENSE.md) for the full terms.

---

## Author

**Dmitry Isaenko** - full-stack Laravel developer building SaaS tools.

- Website: [larafoundry.com](https://larafoundry.com)
- Dev.to: [@d_isaenko_dev](https://dev.to/d_isaenko_dev)
- LinkedIn: [Dmitry Isaenko](https://linkedin.com/in/d-isaenko-dev)
- X: [@d_isaenko_dev](https://twitter.com/d_isaenko_dev)
