# AGENTS.md — LaraFoundry core (dev-context for agents working ON the package)

This file is for an AI agent (or human) **developing the `dmitryisaenko/larafoundry` package itself**. If you are instead *consuming* the package in a host app, read [`docs/integrating-into-an-existing-app.md`](docs/integrating-into-an-existing-app.md) and [`docs/README.md`](docs/README.md) — not this file.

> Companion: `CLAUDE.md` (same directory) points Claude Code here.

## What this package is

A reusable SaaS/CRM **core** for Laravel, extracted phase-by-phase from a production app and modernised (with a security pass on every lifted piece). Proprietary licence. It ships as a Composer package and is consumed by host apps over a `path`/Packagist repository.

- **Composer name:** `dmitryisaenko/larafoundry` · **type:** `library`
- **Namespace:** `Dmitryisaenko\LaraFoundry\` → `src/` (PSR-4). Tests: `Dmitryisaenko\LaraFoundry\Tests\` → `tests/`.
- **Requires:** PHP `^8.2`, Laravel `^12 || ^13`, Inertia `^2 || ^3`, Fortify, Sanctum, Socialite, Ziggy, `intervention/image`, `laravolt/avatar`, `spatie/laravel-activitylog`, `bacon/bacon-qr-code`, `ezyang/htmlpurifier`.
- **Stack the core ships UI in:** Inertia + Vue 3 + Tailwind 4, vue-i18n, Pest.

## Golden rules

1. **Never edit the host.** The core exposes a small fixed set of *seams* and a host plugs into them; the core never asks a host to patch its code. Add behaviour by adding a seam, not by assuming host code. The seams: model traits, package install/migrations, provider registries (`MenuProvider`/`DashboardWidgetProvider`/export/purge registries), config registries, published pages + barrel components, and core services called from host domain (see `docs/README.md`).
2. **Contracts + bindings for anything swappable.** A capability that a host might replace lives behind a contract in `*/Contracts` with a default bound in the service provider (e.g. `MediaStorage`, `AvatarGenerator`, `GeoResolver`, `TenantResolver`, `EntitlementResolver`, `PlanRepositoryContract`, `RegionContext`). Call the contract, never the concrete.
3. **Fail-closed.** Security/tenancy defaults deny: `TenantScope` returns zero rows with no active tenant and throws on an unscoped create; the settings store and permission catalog are fail-closed to their registries; the billing access gate is fail-closed both ways. Keep new code in this spirit.
4. **Config-driven registries over hard-coded lists.** Activity-log events, settings keys, ui_settings keys, email templates, legal pages, OAuth providers, plans — all declared in a published `config/larafoundry*.php` and validated against that registry. Arbitrary keys are rejected.
5. **HTML that reaches mail or a page goes through `HtmlSanitizer`** (the email-template + legal-page editors render super-admin HTML; never let stored content execute).
6. **i18n keys are the English source text.** Frontend strings use `{{ $t('English text') }}`; the bundled `lang/frontend/{locale}.json` translates them. No separate key namespace.
7. **Semver.** Public API (trait method names, config keys, published page contracts, shared-prop shapes, route names) is a contract — break it only with a major bump. Each shipped phase gets a tag.

## Repository layout

```
src/                 # one folder per domain module (PSR-4 Dmitryisaenko\LaraFoundry\)
  ActivityLog/  Auth/ (+ Auth/Qr/)  Authorization/  Billing/  Console/  Contracts/
  Dashboard/  Email/  Http/ (cross-cutting middleware)  Legal/  Media/  Navigation/
  Notifications/  Profile/  Settings/  Tenancy/  Tickets/
  LaraFoundryServiceProvider.php          # the ONE provider (auto-discovered)
config/              # larafoundry.php + larafoundry-{permissions,activitylog,media,notifications,tickets,email,legal}.php
database/migrations/ # auto-loaded into the host (loadMigrationsFrom) — NOT published
routes/              # web.php, auth.php, api.php, qr.php, admin.php, tenancy.php, … (auto-loaded)
lang/                # PHP groups + lang/frontend/{en,uk}.json (the vue-i18n bag)
resources/js/        # Inertia Pages/ (published) + components/, layouts/, composables/, i18n/, index.js (barrel)
resources/css/       # theme.css (Tailwind v4 @theme tokens)
resources/views/     # mail/ + the Inertia root view
tests/               # Pest on Orchestra Testbench
docs/                # host-integrator reference (one page per module) + integration guide
```

A module typically holds: `Models/`, `Http/Controllers/`, `Http/Requests/` (FormRequests), `Http/Middleware/`, `Actions/`, `Policies/`, `Support/`, `Contracts/`, `Concerns/` (traits), `Console/Commands/`, `Providers/`, `Events/`, `Listeners/`. Prefer **Actions** for write operations, **FormRequests** for validation/authorization, **Policies + Gates** for authorization, thin controllers.

## The single service provider

`src/LaraFoundryServiceProvider.php` is the spine. It is auto-discovered (`composer.json` → `extra.laravel.providers`). Read it before any structural change.

- **`register()`** — `mergeConfigFrom` every config; bind swappable contracts to defaults; replace Fortify's scaffolded actions with the hardened ones (`CreatesNewUsers`→`CreateNewUser`, etc.); bind the tenant resolver per `tenancy.mode` (the only mode-aware seam: `personal`→`PersonalTenantResolver`, else `SessionTenantResolver`); seed the export/purge/menu/dashboard registries.
- **`boot()`** — `loadMigrationsFrom`, `loadRoutesFrom` (one file per concern; `tenancy.php`/`authorization.php` only when `mode !== 'personal'`), `loadTranslationsFrom`, `loadViewsFrom`; register middleware aliases (`larafoundry.*`); wire event listeners (failed-login alerts, OTP-flag reset on login/logout, welcome-on-verified, cookie-consent sync); register policies; `registerPublishing()` + `commands()` only `runningInConsole()`.
- **Publishing tags** (declared in `registerPublishing()`): `larafoundry-config`, `larafoundry-permissions`, `larafoundry-activitylog`, `larafoundry-media`, `larafoundry-notifications-config`, `larafoundry-tickets-config`, `larafoundry-email-config`, `larafoundry-legal-config`, `larafoundry-pages`, `larafoundry-mail-views`, `larafoundry-lang`. (Mind the `-config` suffix on the notifications/tickets/email/legal config tags.)
- **Commands:** `larafoundry:install`, `larafoundry:permissions:sync`, `larafoundry:prune-sign-in-requests`, `larafoundry:prune-notifications`, `larafoundry:purge-deleted-accounts`.

## Multi-tenancy modes

`config('larafoundry.tenancy.mode')` is `teams` (default) or `personal`. The `TenantResolver` contract is the only mode-aware binding; everything else depends on it. Domain models gain isolation with `use BelongsToTenant` — it scopes by `company_id` in teams mode and `user_id` in personal mode (config-driven foreign key), fail-closed. In `personal` mode the company/role-management routes are not even loaded.

## Frontend conventions

- **Pages** live in `resources/js/Pages/*.vue` and are **published** into the host (`larafoundry-pages`); the host's `import.meta.glob('./Pages/**/*.vue')` resolves them.
- **Shared library** (components, layouts, composables, i18n, `createLaraFoundry`, `dashboardWidgets`, `registerDashboardWidget`) is exported from the barrel `resources/js/index.js`. Pages and host code import it as **`@dmitryisaenko/larafoundry`** — a bare specifier the host resolves with a **Vite alias** to `vendor/.../resources/js/index.js`. **There is no `package.json`/npm publish**; do not add `import`s that assume one.
- `createLaraFoundry(app, pageProps)` installs vue-i18n (global `$t`) and registers shared components. Ziggy + the Inertia plugin stay the host's responsibility.
- `theme.css` (Tailwind v4 `@theme` tokens) is imported from `vendor/`.
- `QrScanner.vue` is deliberately **not** in the barrel (it lazy-imports the heavy `html5-qrcode`); a host imports it by subpath.
- Backend shared props: `Http/Middleware/HandleInertiaRequests` shares only infrastructure; host-facing prop helpers `LaraFoundry{Tenancy,Authorization,Navigation}::sharedProps()` add the rest. Keep prop **shapes** stable (they are public API).

## Testing

- **Pest** (`^3 || ^4`) on **Orchestra Testbench** (`^10 || ^11`). `phpunit.xml` defines `Feature` + `Unit` suites; coverage source is `src/`.
- Two base cases: `tests/TestCase.php` (lean, DB-less, just registers the provider) and `tests/AuthTestCase.php` (heavier: DB migrations + Fortify/Socialite). `tests/Pest.php` binds folders to one or the other — a DB-touching suite must be bound to `AuthTestCase` there.
- Fixtures: `tests/Fixtures/{User,Note,AuditableNote}.php` (the canonical host-model composition is `tests/Fixtures/User.php`). Test-only tables: `tests/migrations/`.
- **Run:** `composer test` (= `pest`) · a slice: `vendor/bin/pest --filter=QrLogin` · `composer test -- --parallel`.
- **Testbench config-leak trap:** publishing in a testbench run can leave `vendor/orchestra/testbench-core/laravel/config/larafoundry*.php` behind, which then shadows real config. The composer `post-autoload-dump` script (`@purge-testbench-config`) deletes them; if config behaves oddly in tests, run `composer dump-autoload` to re-purge.

## Lint & style

- `composer lint` (= `pint`) to fix; `composer lint:test` (= `pint --test`) to check. Laravel preset.
- Every PHP file: `declare(strict_types=1);`. Typed properties/returns. Match the density and idiom of the surrounding module.

## Quality / security gate (before a tag)

Bring the change to green and run the gate, then hand off — **git is the maintainer's**: the agent provides the commit name (and tag), never runs `git commit`/`git tag`/`git push`.

1. `composer test` green + `composer lint:test` clean.
2. `/security-review` + `/code-review` on the change (large/cross-cutting → `/code-review ultra`).
3. Update the relevant `docs/` page and the README roadmap row if a phase shipped.
4. Hand the maintainer the commit name (plain-text English) and the semver tag.

## Where to look

- Per-module integrator reference: `docs/README.md` + `docs/modules/*.md`.
- Consuming the core in an existing app (install, personal mode, login-only, OAuth/QR, frontend wiring): `docs/integrating-into-an-existing-app.md`.
- The roadmap + shipped phases: the README roadmap table.
