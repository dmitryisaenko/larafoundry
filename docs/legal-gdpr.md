# Legal pages and GDPR

Phase 5.3 is the legal and GDPR layer most SaaS apps bolt on late. It ships three things a host wires in one pass:

- **Legal pages**: a super-admin editor for Terms, Privacy and Cookie policy, stored in the database, versioned, served on a public `/legal/{slug}` route.
- **Consent**: a cookie banner (off by default), a registration Terms checkbox, and a re-accept gate that triggers when the published Terms version changes.
- **GDPR rights**: personal-data export and account erasure, built as two mirrored registries so the right to access and the right to be forgotten plug in the same way.

This is the accurate reference for the shipped package (core `v0.17.x`).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)

## Install

Everything ships with the core package; there is nothing extra to require (the HTML sanitizer the legal editor reuses came with the email-template module in `v0.16.x`) and no new trait on your `User` (the `user_deleted_at` and `user_purged_at` columns are part of the core user migrations).

A host opts in by running the migrations and publishing the Inertia pages:

```bash
php artisan migrate
php artisan vendor:publish --tag=larafoundry-pages
```

The migrations load automatically:

- `larafoundry_legal_pages` (the editable legal pages)
- a `user_purged_at` column added to `users` (the idempotency stamp for erasure)

The published pages are `Legal/Show`, `Legal/Accept`, `Admin/Legal/Index`, `Admin/Legal/Edit` and `Profile/sections/DataExport`. The operator "Legal pages" entry is wired into the core admin menu automatically; the public legal routes and the consent routes load from the package.

Three pieces of wiring are the host's, because the core does not own your middleware stack, your scheduler or your root Vue app:

1. The re-accept gate. Add the `larafoundry.terms` middleware (alias already registered by the core) to your web group, after the auth-context middleware:

```php
// bootstrap/app.php
use Dmitryisaenko\LaraFoundry\Legal\Http\Middleware\EnsureTermsAccepted;

$middleware->web(append: [
    // ... the core middleware you already wired ...
    EnsureTermsAccepted::class,
]);
```

It is fail-open and guest/super-admin safe, so it is a no-op until you publish a Terms page.

2. The erasure cron. The core ships the command but no scheduler:

```php
// routes/console.php
Schedule::command('larafoundry:purge-deleted-accounts')->daily()->withoutOverlapping();
```

3. The cookie banner. Mount it once, globally, so it shows on every page regardless of layout. It stays hidden until you enable cookie consent, so mounting it is safe even if you never turn it on:

```js
// resources/js/app.js
import { createLaraFoundry, CookieConsentBanner } from '@dmitryisaenko/larafoundry';

createInertiaApp({
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => [h(App, props), h(CookieConsentBanner)] })
            .use(plugin);
        createLaraFoundry(app, props.initialPage.props);
        app.mount(el);
    },
});
```

The registration Terms checkbox is already in the core `Register.vue`. If your host renders its own registration page, read `consent.terms_required` from the shared props and add a `terms` checkbox the same way (the backend enforces the same condition, so the two never disagree). The `consent` shared prop is supplied by the core `HandleInertiaRequests`, so a host that extends it (and calls `parent::share()`) gets it with no extra work.

## Configuration

The legal config is a new file, so it merges in automatically; publish it only to change the defaults:

```bash
php artisan vendor:publish --tag=larafoundry-legal-config
```

### `config/larafoundry-legal.php`

```php
'cookie_consent' => [
    'enabled' => false,                              // OFF by default (core sets only strictly-necessary cookies)
    'cookie' => 'larafoundry_cookie_consent',        // the encrypted decision cookie for guests
    'lifetime_days' => 365,
],

'terms' => [
    'enforce' => true,                               // the kill-switch; fail-open until a Terms page is published
    'slug' => 'terms',                               // which legal page drives the gate
    'except_routes' => [],                            // extend the built-in bypass list (logout, accept screen, legal pages, verification)
    'except_prefixes' => [],
],

'erasure' => [
    'grace_days' => 30,                              // how long a soft-deleted account is recoverable before anonymising
    'anonymized_email_domain' => 'deleted.invalid',  // RFC 2606 reserved TLD: an anonymised address is never deliverable
],

'pages' => [
    'terms'   => ['title' => [...], 'body_html' => [...]],   // placeholder defaults per locale
    'privacy' => ['title' => [...], 'body_html' => [...]],
    'cookies' => ['title' => [...], 'body_html' => [...]],
],
```

The `pages` registry is the single source of truth for which legal slugs exist (fail-closed): a super-admin can only edit a registered slug, and `/legal/{slug}` is served only for a registered, published slug. Add a legal page by adding one entry here; the editor picks it up automatically. The placeholder `body_html` is never served as real legal text: an unpublished page 404s, so the owner edits and publishes it under their own jurisdiction before launch.

The data-export rate limit lives in the core config (it is a profile route):

```php
// config/larafoundry.php
'profile' => [
    'data_export' => ['throttle' => '3,1440'],       // 3 downloads per 1440 minutes (a day)
],
```

## Usage

### Editing and publishing legal pages

In the operator console, "Legal pages" lists the registered slugs (Terms, Privacy, Cookie policy). A super-admin edits the title and HTML body per locale, previews the sanitized result, and publishes. Publishing stores the override and bumps the page version. Until a page is published, its public URL 404s.

The public pages are open to everyone at `/legal/{slug}` (for example `/legal/terms`). The body is sanitized on save and on render through the same `HtmlSanitizer` the email editor uses, so a stored page cannot smuggle script into a public route.

### The Terms gate

Bumping the published Terms version turns the `larafoundry.terms` middleware on for every signed-in user: on their next request they are redirected to a re-accept screen, where they read the updated Terms and accept (or log out). Accepting records the version against their account and lets them continue where they were headed.

The gate is fail-open. With no published Terms page (the default on a fresh install) it enforces nothing, so it never traps a user behind a page that does not exist. It also exempts the super-admin and lets guests through, and it answers a JSON/XHR client with a 403 instead of an HTML redirect.

### Cookie consent

The banner ships off, because the core sets only strictly-necessary cookies (session, CSRF, locale, the appearance toggle), which do not require consent. Turn it on (`cookie_consent.enabled = true`) once you add non-essential cookies such as analytics or marketing. The decision is binary (accept all, or necessary only), stored in an encrypted cookie for a guest and synced to the `cookie_consent` user setting on login (the source of truth once authenticated).

### Data export (right to access)

A signed-in user downloads a JSON copy of everything the app holds about them from the profile danger zone (`GET /profile/data/export`, rate-limited). The file is assembled synchronously from every registered `ExportsUserDataProvider`: the core contributes profile, sessions, UI settings and consent; the notifications and tickets modules contribute their own sections in their own boot.

### Account erasure (right to be forgotten)

Deleting an account is a reversible soft-delete. It sets `user_deleted_at`, signs the user out and hides them at once, and starts a grace clock (`grace_days`, default 30). A super-admin can restore the account during the window. Once the window elapses, the daily `larafoundry:purge-deleted-accounts` command runs every registered `PurgesUserData` inside a transaction and stamps `user_purged_at`. The core purger anonymises the identity (name, email, password, two-factor secrets, OAuth tokens, sessions, personal access tokens, avatar) rather than hard-deleting the row, so foreign keys stay intact and legal records that must survive can be kept against a faceless identity. Module purgers delete what the user owns. The activity log is deliberately not scrubbed: anonymise the who, keep the what, because the trail is the proof the erasure ran.

### Registering your own export and purge (your domain data)

The two registries are singletons (like the menu builder). When you add a module that owns user data, register one provider on each, in a service provider's `boot()`:

```php
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataExportRegistry;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataPurgeRegistry;

$this->app->make(UserDataExportRegistry::class)->addProvider($this->app->make(OrdersExporter::class));
$this->app->make(UserDataPurgeRegistry::class)->addProvider($this->app->make(OrdersPurger::class));
```

`OrdersExporter` implements `ExportsUserDataProvider` (`exportFor`, `key`, `priority`); `OrdersPurger` implements `PurgesUserData` (`purgeFor`, `key`, `priority`). Both rights then cover orders, and neither the export flow nor the erasure flow needs to know orders exist. A purger decides for itself whether its data is deleted or anonymised, and it MUST be idempotent (the cron may retry a row whose previous run failed part-way). Section keys are unique across each registry; a clash fails loud rather than silently overwriting.

## API reference

### Public, consent and erasure routes

```
GET    /legal/{slug}                            larafoundry.legal.show             public, registered + published slug only
POST   /consent/cookies                         larafoundry.consent.cookies        guest + auth, records a cookie decision
GET    /terms/accept                            larafoundry.consent.terms.show     the re-accept screen (auth)
POST   /terms/accept                            larafoundry.consent.terms.accept   records acceptance (auth)
GET    /profile/data/export                     profile.data.export                JSON download (auth, throttled)
DELETE /profile/account                         profile.account.destroy            starts the grace-period soft-delete
```

Operator legal editor (behind the admin gate plus the OTP step-up):

```
GET    /admin/legal-pages                        admin.legal-pages.index
GET    /admin/legal-pages/{slug}/edit            admin.legal-pages.edit
PUT    /admin/legal-pages/{slug}                 admin.legal-pages.update
POST   /admin/legal-pages/{slug}/preview         admin.legal-pages.preview
```

The host does not define these; it links to them.

### `larafoundry.terms` middleware

`EnsureTermsAccepted`. Redirects a signed-in user whose accepted Terms version is stale to `larafoundry.consent.terms.show`. Fail-open (no published Terms = no-op), exempts the super-admin and guests, answers XHR/JSON with 403. Extend its bypass list with `terms.except_routes` / `terms.except_prefixes`.

### `larafoundry:purge-deleted-accounts` command

Erases accounts soft-deleted past `erasure.grace_days`. Idempotent (a purged row is stamped `user_purged_at` and never reprocessed; a row that failed part-way is retried next run). The host schedules it; the core owns no scheduler.

### `ConsentManager` (the single consent authority)

| Method | Purpose |
|--------|---------|
| `termsRequired()` | Enforcement on AND a Terms page is published. The checkbox and the gate branch on this. |
| `needsTermsAcceptance($user)` | Whether this user must (re-)accept right now. |
| `recordTermsAcceptance($user, ?int $version = null)` | Store the accepted version + timestamp and audit it. |
| `currentTermsVersion()` | The published Terms version, or null (the fail-open signal). |
| `cookieConsentEnabled()` / `cookieConsentDecided($request)` | Whether the banner is on, and whether a decision exists. |
| `recordCookieConsentForUser($user, bool $accepted)` | Persist a logged-in user's cookie choice and audit it. |

### Export and purge registries

| Class | Method | Purpose |
|-------|--------|---------|
| `UserDataExportRegistry` | `addProvider(ExportsUserDataProvider)` | Register an export section (unique key, fail-loud on clash). |
| `UserDataExportRegistry` | `collect($user)` | Build the export, each section under its key. |
| `UserDataPurgeRegistry` | `addProvider(PurgesUserData)` | Register a purger (unique key, fail-loud on clash). |
| `UserDataPurgeRegistry` | `purge($user)` | Run every purger in priority order. |

The contracts: `ExportsUserDataProvider` (`exportFor`, `key`, `priority`) and its mirror `PurgesUserData` (`purgeFor`, `key`, `priority`).

### `LegalPageRepository`

Resolves a legal page from the registry union the database. `save($slug, $attributes)` stores a super-admin override (and bumps the version on publish); `currentVersion($slug)` returns the published version or null. Public rendering and the admin editor both go through it, so the registry stays the single source of truth.

## Security notes

- **Legal slugs are fail-closed.** Only slugs declared in the `pages` registry can be edited or served, so a super-admin cannot invent an arbitrary public page and an unknown slug 404s.
- **Placeholders are never served as legal text.** An unpublished page 404s rather than showing the default copy, so the owner's real, jurisdiction-specific text is the only thing the public ever sees.
- **Legal HTML is sanitized on write and on render** through the shared `HtmlSanitizer` (scripts, event handlers and dangerous URLs dropped), and there is no variable rendering on legal pages, so the template-injection class that mattered for emails does not exist here.
- **The Terms gate is fail-open and loop-safe.** It enforces nothing until a Terms page is published, exempts the super-admin and guests, and whitelists its own accept screen and the legal pages, so it can never trap a user in a redirect loop.
- **The cookie decision cookie stays encrypted.** Do not add it to `EncryptCookies::$except`; encryption is what makes a guest's decision tamper-resistant, so exempting it would let anyone forge an accept.
- **Erasure anonymises, it does not orphan.** The user row survives, faceless, so foreign keys stay intact; secrets (password, two-factor, OAuth tokens, sessions, personal access tokens) are wiped, and the email becomes an unreachable reserved address. The purge runs in a transaction and is idempotent.
- **The audit trail survives erasure on purpose.** Identity is anonymised everywhere, but the activity log of what happened (including the erasure itself) is kept as the proof you honoured the request.
- **The legal editor is super-admin only**, behind the admin zone, the OTP step-up and an explicit policy. Data export and account deletion are the caller's own, scoped server-side from auth, never an id from the request.

## Testing

Legal and consent live in `tests/Feature/Legal/`, export and erasure in `tests/Feature/Profile/` and `tests/Unit/Profile/`. Notable coverage:

- The Terms gate: redirects a stale user, lets an up-to-date one through, is fail-open with no published Terms, exempts the super-admin and guests, 403s an XHR client, and respects the route/prefix bypass list without leaking a shared-prefix path.
- Consent recording: a guest cookie decision, the authenticated `cookie_consent` setting, the first-login adoption of a guest choice, and the registration Terms requirement (required when published, absent until then).
- The export registry: each provider's section is collected under its key, with a duplicate-key guard.
- The purge registry and command: the core purger anonymises identity and wipes every secret, module purgers delete only the user's own data, and the cron anonymises accounts past the grace window, stamps them, is idempotent, and leaves recent and active accounts alone.

Run them with Pest:

```bash
composer test
```
