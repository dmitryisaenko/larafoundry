# LaraFoundry

> A reusable SaaS/CRM core for Laravel, extracted in public from a production system.

LaraFoundry is a modular SaaS foundation being extracted from [Kohana.io](https://kohana.io), a real production CRM/ERP. The goal is to package the cross-cutting parts every SaaS rebuilds from scratch (auth, multi-tenancy, i18n, admin, billing) as a clean, tested Composer package, so you don't write them again.

This is built **in public** and **by extraction, not rewrite**. Each piece is pulled from battle-tested production code, modernized, hardened, covered with Pest, reviewed, and only then tagged. The README tracks what is *actually in the package*, not what is planned. See the roadmap for what's coming.

**Tech stack:** Laravel 12 / 13, PHP 8.2+, Inertia 2 / 3, Vue 3, Tailwind CSS 4, Ziggy, Pest. Authentication builds on [Laravel Fortify](https://laravel.com/docs/fortify) and [Socialite](https://laravel.com/docs/socialite); the activity log builds on [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog); the media library builds on [intervention/image](https://image.intervention.io) and [laravolt/avatar](https://github.com/laravolt/avatar); the email-template editor builds on [ezyang/htmlpurifier](https://github.com/ezyang/htmlpurifier).

```bash
composer require dmitryisaenko/larafoundry
```

> ⚠️ **Status: early but growing. Current release is `v0.33.x`: foundation, authentication (incl. the super-admin OTP gate, session PIN-lock, QR cross-device login and a page/modal presentation switch), multi-tenancy, RBAC, the platform activity log, multilanguage, the navigation engine + operator-console screens (Admin Users + impersonation, Admin Companies + block cascade, the Admin Dashboard), the file / media library, the billing seam, the in-app notification centre with super-admin broadcasts, support tickets, the settings / profile / email-template service modules (incl. a consolidated profile hub with a per-user date format and a searchable company-time-zone dropdown), the legal / GDPR layer (editable legal pages, cookie and Terms consent, personal-data export, grace-period account erasure), optional role-at-invite (assign a teammate's role on the invitation, company-scoped and fail-closed), an extensible admin-access security alert (failed super-admin password/OTP/PIN, mail by default, host-pluggable channels), config-driven OAuth with community-driver auto-registration, AI dev-context shipped inside the package (`AGENTS.md` / `CLAUDE.md`) so a coding agent understands the engine on require, a collapsible "My company" navigation group, a hardened themed confirm dialog, direct employee provisioning (an owner creates and edits a company member, avatar and roles, without an email invite), owner-driven company archiving, a per-user time format, admin base surfaces for hosts (user locale / auth columns, a payments upsell stub, an extensible admin user-column seam), a completed activity-log surface (role CRUD, profile / password, admin-access and file-upload events), a full transactional / marketing email-template CRUD editor, an operator Users-management parity pass (opt-in PII columns, country / sex / age / phone-verified filters, forced verify / unverify, block-with-reason, per-user activity logs, social-link storage), and an email-template preview command (`larafoundry:mail-preview`) with a template x locale i18n-parity test matrix, a monetization upsell-stubs surface (the Payments, Affiliates and Promo operator-console screens reserved as inert upsell placeholders the paid `larafoundry-billing` add-on lands on), an SEO kit (a request-scoped `SeoManager` with server-rendered `<head>` meta through a `@larafoundrySeo` Blade directive and a client `<Seo>` component, a sitemap provider registry with public `sitemap.xml` / `robots.txt` routes, hreflang and a thin config-driven OG-image seam), and a gentle, dismissible onboarding checklist (a provider registry of auto-detected getting-started steps, per-user dismiss, rendering nothing when there is nothing to nudge).**
> The Admin Dashboard is the operator console's landing screen: free-core widgets for users, companies and recent activity, built on a pluggable widget seam (the exact mirror of the navigation menu seam) so a host or the paid add-on can inject more widgets without touching the core. It is revenue-agnostic; a revenue widget is the paid `larafoundry-billing` add-on, along with real payments, promo codes, trials and subscription management. The other domain modules are not in the package yet; they are being extracted phase by phase. Domain permissions, domain events and the host's own menu items are deliberately the host's job, not the core's. Don't `composer require` this expecting a finished SaaS engine. Expect a hardened set of primitives those modules stand on.

---

## What's in the package

### `v0.33.x` Onboarding checklist

A gentle, dismissible getting-started checklist for a new user, built on the same provider-registry shape as the dashboard widgets. It nudges, it never forces: every step's completion is read from live state (never a stored "done" flag, so a step un-checks if the user later clears it), and the whole thing renders nothing the moment there is nothing left to nudge. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| `OnboardingStepProviderInterface` + `OnboardingBuilder` | The provider registry, 1:1 with the `DashboardBuilder` seam. Providers contribute steps; the builder merges, filters and serialises them. The build returns null for a guest or the platform super-admin, and short-circuits (one settings read, no per-step queries) for a user who has dismissed the checklist. A host adds its own steps by registering a provider on `app(OnboardingBuilder::class)`. |
| `CoreOnboardingStepProvider` (4 auto-detected steps) | Complete your profile (name + last name set), enable two-factor, set up your company (the active company is out of setup), invite a teammate (more than one member, or a pending invitation). The last two are teams-only and are omitted in `personal` tenancy mode. Completion is auto-detected from real state, never a flag. |
| Per-user dismiss | A `POST /onboarding/dismiss` endpoint writes a per-user `onboarding.dismissed` boolean through the settings store, so a user closes the checklist for good. It reuses the existing `larafoundry_settings` table: **no new migration**. |
| `<OnboardingChecklist>` component | Shipped to the frontend as the `onboarding` shared prop via `LaraFoundryOnboarding::sharedProps()` and consumed by the `<OnboardingChecklist>` Vue component (exported from the package barrel). It renders nothing for a guest, the operator, a dismissed user, an all-complete user, or a user with no steps. |

> The host merges `\Dmitryisaenko\LaraFoundry\Onboarding\LaraFoundryOnboarding::sharedProps()` into its `HandleInertiaRequests::share()` and places `<OnboardingChecklist>` on its home page. The dismiss route and the step registry ship in the package; there is no new migration. Full reference: [docs/onboarding.md](docs/onboarding.md).

### `v0.32.x` SEO kit

An SEO toolkit for the Inertia SPA, so the core's pages are crawlable and shareable without bolting on a Node SSR daemon. A request-scoped `SeoManager` feeds two consumers from one source: a server-rendered `<head>` for crawlers and social unfurlers, and a shared Inertia prop the client updates on SPA navigation. It ships a sitemap provider registry (the same shape as the navigation menu seam), public `sitemap.xml` / `robots.txt` routes, hreflang, and a thin, config-driven OG-image seam. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| `SeoManager` (request singleton) | Fluent setters (title, description, canonical, robots, noindex, ogImage, ogType); unset values fall back to config or sensible defaults. `renderHead()` returns server-rendered `<head>` HTML (title, description, canonical, Open Graph, Twitter card, hreflang), emitted through a `@larafoundrySeo` Blade directive the host drops into its `app.blade.php` head - the crawler / social path, no Node SSR needed. `toArray()` ships as the `seo` shared prop for a client `<Seo>` Vue component to keep meta current across SPA navigation. |
| Sitemap provider registry | `SitemapProviderInterface` + `SitemapBuilder` (same shape as the navigation `MenuBuilder`); the core's `LegalSitemapProvider` emits only **published** legal pages. Public routes `sitemap.xml` and `robots.txt` (`routes/seo.php`, web-only, no auth); the XML is cached. Every `<loc>` is built from the configured canonical base (`larafoundry-seo.canonical.base_url`, else `app.url`), **not** the request host, closing a Host-header cache-poisoning bug the review found. |
| hreflang + robots defaults | hreflang alternates are emitted from `config('larafoundry.locale.available')`. `robots` defaults to `index,follow`; the sensitive Fortify screens (reset-password, confirm-password, verify-email, two-factor-challenge) are set `noindex`, and legal pages index with their real title. |
| Thin OG-image seam | A config default static image (`larafoundry-seo.og.default_image`) resolved through a rebindable `OgImageResolver` contract (default `ConfigOgImageResolver`, resolving relative paths via the core `MediaStorage` contract). **No dynamic image generation** - a host that wants generated cards binds its own resolver. |

> Host integration: (1) add `@larafoundrySeo` inside the `<head>` of the host `app.blade.php`; (2) merge `\Dmitryisaenko\LaraFoundry\Seo\LaraFoundrySeo::sharedProps()` into the host's `HandleInertiaRequests::share()`; (3) optionally publish `larafoundry-seo-config` (tag `larafoundry-seo-config`, the new `config/larafoundry-seo.php`) and set the `LARAFOUNDRY_SEO_*` env. The `sitemap.xml` / `robots.txt` routes and the `<Seo>` component ship in the package. Every meta value is escaped, every URL is http(s)-scheme-guarded before emission, and the sitemap is XML-escaped. Full reference: [docs/seo.md](docs/seo.md).

### `v0.31.x` Monetization upsell stubs

The free core reserves the Payments, Affiliates and Promo screens in the operator console as inert **upsell placeholders**, so a host has the surface from day one and the paid `larafoundry-billing` add-on has a clean place to land. The real payments, promo-code and affiliate functionality lives in the add-on, not here; the core ships only the reserved slots and the seam the add-on overrides them through. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| Three reserved console slots | The existing Payments stub gains an upsell CTA; two new stub screens, Affiliates (`admin.affiliates.index`) and Promo codes (`admin.promo.index`), join it. Each is a stub controller returning an Inertia empty-state page (`billing_enabled` + `upsell_url` props), a route inside the same `larafoundry.admin` + OTP-gated admin group, a nav `MenuItem`, a Vue page (mirroring the Payments stub) and an icon. |
| Upsell CTA | The CTA renders only when billing is disabled **and** a URL is set, driven by `larafoundry.upsell.billing_url` (env `LARAFOUNDRY_UPSELL_BILLING_URL`, default `https://larafoundry.com`). With the paid add-on installed it stays quiet. |

> No new override toggle was added to the core: the paid add-on re-points the named route and swaps the Inertia page through the **existing** seam (named routes + Vue-page-by-string + rebindable bindings). No migration. Full reference: [docs/monetization-stubs.md](docs/monetization-stubs.md).

### `v0.30.x` Operator Users-management parity

The operator console's Users screen grew up to match the production donor: the table, its filters and the per-user actions an operator actually needs. Privacy stays the default, every PII column is opt-in, so a fresh public install shows nothing sensitive. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| Opt-in PII columns | Phone, sex, age and social links are extra columns switched on per host through a config allowlist (`larafoundry.admin.user_columns`). The default is empty, GDPR-clean for a fresh install; the resource gates each field with `when()` so a disabled column never leaves the server. The token list is sanitized against a fixed set, so an unknown token is a no-op, not an error. |
| Table filters | Country, phone-verified, sex and an age-range filter, each a no-op on unknown input, plus the existing search. The age buckets are disjoint by construction. |
| Forced verify / unverify | An operator can force-verify or unverify a user's email or phone directly (audited, no mail and no SMS sent), and block a user with a required reason through the themed dialog. |
| Per-user context actions | Each row links to that user's own activity log (filtered by causer) and to a pre-filled "create ticket" screen, so the operator moves from a user to their history or a support thread in one click. |
| Social-link storage | Social profiles live in their own `larafoundry_user_social_links` table (not a JSON blob), whitelisted by platform, each URL validated http/https only (anti-XSS). A widget edits them on the user form; the column renders fixed-dictionary icons. |

> The host runs `php artisan migrate` (the `larafoundry_user_social_links` table) and `vendor:publish --tag=larafoundry-pages`. PII columns stay off until the host lists them in `larafoundry.admin.user_columns`. The core's frontend translation keys reach the host automatically through the layered translation loader, so the host duplicates nothing.

### `v0.29.x` Email-template CRUD editor

The email-template module (`v0.16.x`) went from "edit the wording of the core's transactional mail" to a full editor with two clearly separated layers, so a host can also author its own standalone templates without ever forking a core transactional code.

| Component | What it does |
|-----------|--------------|
| Two template types | **Transactional** templates are registry-driven (code-defined, not deletable or renamable, subject / body / active only), keeping the fail-closed fallback to static lang wording. **Marketing** templates are self-contained database entities with their own code, name, variables, subject and body, and a full create / duplicate / delete lifecycle. |
| Safe duplicate | Duplicating any template always produces a marketing copy, so a transactional code can never be forked into a second sender. The whole editor keeps the single-pass `{{token}}` renderer (no Blade, no eval), the allowed-variable check on save and HTMLPurifier on the body. |

> Additive migration only (a `type`, `name` and `variables` column on `larafoundry_email_templates`, no `migrate:fresh`). The host runs `php artisan migrate` and `vendor:publish --tag=larafoundry-pages`. The `larafoundry:mail-preview` command (renders every template x locale, with `--html` / `--log` output and a health check) ships alongside for eyeballing the result. Full reference: [docs/settings-profile-email.md](docs/settings-profile-email.md).

### `v0.28.x` Activity-log completeness

The platform activity log (`v0.5.x`) recorded model diffs but missed the operator-relevant events every audit trail is asked for. This band closed the gaps so the log answers "who changed what" across the console.

| Component | What it does |
|-----------|--------------|
| Fuller event coverage | Role create / update / delete, profile and password updates (wired from Fortify's own actions), the admin-access failure event and a file-upload event now record, and the admin Settings / Legal / Email screens write an audit entry on save. Events are grouped by concern (Authorization, Auth, Media), and the anonymise-the-who-keep-the-what rule from the GDPR layer still holds. |

> No new trait and no new migration. The completeness is automatic once the package is required.

### `v0.27.x` Admin base surfaces for hosts

A set of small operator-console surfaces a host needs before it starts adding domain screens: extra user columns, a payments landing, company-archive events and, most importantly, a seam that lets a host add its own user columns without forking a core Vue component.

| Component | What it does |
|-----------|--------------|
| Language + auth columns | The admin Users table can show each user's locale and auth type (OAuth vs password, with the provider name), filtered server-side, each filter a no-op on junk input. |
| Payments stub | A gated `admin.payments.index` route and empty-state screen (with a `billing_enabled` flag) plus a nav entry, so the paid `larafoundry-billing` add-on has a place to slot real payments into. |
| Company-archive events | The company archive / unarchive actions (see `v0.26.x`) raise events a host or add-on can listen to. |
| User-column seam | A host adds its own admin user columns (e.g. "used the demo?") by subclassing `AdminUserResource` and pointing a config key at it, no fork of the core `UsersTable.vue`. The header is the union of keys across rows; the body renders one cell per header column. Documented in `docs/integrating-into-an-existing-app.md`. |

> The host runs `php artisan migrate` and `vendor:publish --tag=larafoundry-pages`. Extra columns and the payments entry stay quiet until the host opts in through config.

### `v0.26.x` Owner-driven company archiving

An owner can archive their own company, the mirror image of the super-admin block cascade but owner-scoped: archiving closes the company to everyone except the owner, who keeps full access so they can unarchive it. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| Archive / unarchive | Owner-only actions (guarded through `ownedCompanies()`) set a `company_archived_at` stamp (written via `forceFill`, kept out of `$fillable`). Archived companies are **not** hidden on the backend, so the owner can still reach and restore them; `setNextAvailableCompany()` skips archived companies when picking the active one for non-owners. |

> The host runs `php artisan migrate` (the `company_archived_at` column). No new trait.

### `v0.25.x` Per-user time format

A companion to the per-user date format (`v0.21.x`): each user picks how times display, independent of the interface language, and a stray-key leak in the appearance preferences was closed.

| Component | What it does |
|-----------|--------------|
| Per-user time format | Each user chooses `auto`, `24h` or `12h`; the `auto` default follows the active locale. Stored through the `ui_settings` allowlist, so no migration. |
| Appearance-key hardening | The appearance preferences no longer leaked raw internal keys (`label` / `labels`) to the frontend. |

> No new trait and no new migration (the preference lives in the existing `ui_settings` column). Re-publish the Vue pages (`vendor:publish --tag=larafoundry-pages`) to pick up the control.

### `v0.23.x`-`v0.24.x` Direct employee management

An owner can now provision a company member directly, without the email-invite round-trip: create the account (`v0.23.x`) and edit it afterward (`v0.24.x`). Both are owner-only and fail closed on the roles they touch. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| Create a member (`v0.23.x`) | An owner creates a member account with a name, an email (unique, and the super-admin address is reserved), a password they set (confirmed), and any number of company-scoped roles. The account is auto-verified (the owner vouches for it). Role ids are validated fail-closed and re-scoped in the action; the whole create is wrapped in a transaction so a partial member is never left behind. |
| Edit a member (`v0.24.x`) | An owner edits a member's name, avatar and roles (not email or password). The avatar upload / delete runs outside the DB transaction (with cleanup after commit); roles sync only when the form explicitly manages them, so omitting the field never silently wipes a member's roles. Anti-IDOR: the target is resolved through the active company's own users, and an owner cannot be edited this way. |

> The host runs `vendor:publish --tag=larafoundry-pages` for the create / edit screens. No new trait. Concrete roles stay in the host's permission config; the core ships only the mechanism.

### `v0.22.x` Navigation grouping and dialog polish

A polish pass on the tenant shell. The core's navigation groups the company-scoped items (members, roles, settings) under one collapsible "My company" section instead of a flat list, and the themed confirm dialog was hardened so it renders once across layouts and answers to the backdrop and the Esc key, so a destructive action always asks first. FREE core, so the code is open. Re-publish the Vue pages (`vendor:publish --tag=larafoundry-pages`) to pick the changes up.

### `v0.21.x` Profile hub, per-user preferences, and in-package AI dev-context

Two strands in this band: the profile module consolidated and grew per-user preferences, and the package began shipping its own AI dev-context so a coding agent understands the engine the moment it is required. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| Profile hub consolidation | The separate account-settings screen folds into the one `/profile` hub: name and email, password, two-factor, PIN, sessions, avatar and preferences on a single tabbed page. If your user menu linked to a standalone account screen, point it at `/profile`. |
| Per-user date format | Each user picks day-first, month-first or ISO, independent of the interface language (format is not the same thing as language). The shared `useDateFormat()` composable reads it everywhere; the `auto` default derives the order from the active locale. Stored through the `ui_settings` allowlist, so no migration. |
| Company time zone dropdown | The company `timezone` setting renders as a searchable select (`timezone_identifiers_list()`), while the `timezone` validation rule still guards the value, so a typo can't slip through. |
| In-package AI dev-context | `AGENTS.md` and `CLAUDE.md` ship at the repo root and `docs/integrating-into-an-existing-app.md` is the consumer walkthrough. A coding agent reads the golden rules (never edit the host, extend through seams, fail-closed, config-driven registries) instead of guessing them. |

> No new trait and no migration for the profile changes (the preferences live in the existing `ui_settings` column). Re-publish the Vue pages (`vendor:publish --tag=larafoundry-pages`) to pick up the consolidated hub and the date-format control. Full reference: [docs/settings-profile-email.md](docs/settings-profile-email.md).

### `v0.20.x` OAuth community-driver auto-registration

A host enabling Apple, Microsoft or any other `socialiteproviders/*` provider no longer wires a `SocialiteWasCalled` listener by hand. A `larafoundry.auth.oauth.community_drivers` map (slug → handler) ships Apple and Microsoft by default; the core registers each mapped driver whose package is installed and whose slug is enabled, guarded by `class_exists` so the core keeps no hard dependency on those packages. FREE core, so the code is open.

### `v0.19.x` Admin-access security alert (+ OAuth provider expansion)

A neutral security signal for failed attempts to enter the platform super-admin account. Every auth step that guards the operator identity converges on one event, routed through one config gate, with the mail channel shipped in the core and any other channel pluggable by the host. FREE core, so the code is open. (The same release also made OAuth provider buttons config-driven with a `google` / `facebook` / `twitter` default set, documented under [Authentication](#v02x-authentication--users) above.)

| Component | What it does |
|-----------|--------------|
| `AdminAccessAttemptFailed` event | The single signal the core raises whenever a failure protects the super-admin account: a bad password, a throttle lockout, a wrong operator-console OTP, or a wrong session PIN. It carries the step, IP, User-Agent and a dependency-free device fingerprint. It is the public **extension point**: a host listens to the same event to deliver the alert over any extra channel without patching the core. |
| Three sources, gated to the operator | `LogFailedLoginAttempt` raises `password` / `lockout` (only when the attempt targeted the super-admin email); `AlertOnAdminOtpFailure` raises `admin_otp` from Fortify's 2FA-failed event (which fires for every user, so it stays silent unless the user **is** the super-admin); `PinController` raises `pin` (the PIN-lock exists for everyone, so the alert fires only on the super-admin's own PIN failure). |
| `AdminAccessAlertPolicy` (three config axes) | The single source of truth for "should this alert fire?", combining three axes that live in one place so the "failure type x channel" matrix never drifts: `notify_admin` (master on/off), `alert_on` (which failure TYPES, e.g. `['admin_otp']` for OTP-only), and `channels` (which CHANNELS deliver; the core knows `'mail'`). |
| Neutral mail default + host channels | The core ships one channel, `SendAdminAccessAlertMail`, sending a localized notification to the super-admin email. Adding Telegram, Slack or anything else is a host listener on the same event that checks `AdminAccessAlertPolicy::shouldAlert($step, $channel)` and adds its channel name to `channels`: **zero edits to the core**. |

> The host sets the recipient (`LARAFOUNDRY_SUPER_ADMIN_EMAIL`), flips the master switch (`LARAFOUNDRY_NOTIFY_LOGIN_FAIL=true`) and optionally narrows `alert_on` / adds channels. No new trait, no migration. Full reference: [docs/admin-access-alert.md](docs/admin-access-alert.md).

### `v0.18.x` Role-at-invite

Inviting a teammate can now carry the role they should get, instead of inviting first and assigning a role afterward. A small feature with two parts worth noting: a company-scoped guard that fails closed, and a role-template clone that no longer depends on queue timing. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| Optional role on invite | An invitation (in the creation wizard and the standalone employees screen) carries an optional `role_id`; on acceptance the invitee is granted that role in the company. The default is no role ("Specify later"), so the existing email-only invite is unchanged. The role **must** belong to the inviter's active company, validated **fail-closed**: Laravel rewrites `where('company_id', null)` to `whereNull('company_id')`, and global/template roles have a null `company_id`, so a null active company would otherwise match them - the rule coalesces a missing company to an impossible id and asserts the column is not null. The same company-scope check is repeated in the action and again at accept time. Three checks, defence in depth. |
| Synchronous role-clone ensure | Role templates clone into a new company asynchronously (database + cron queue, no daemon, no Redis), but the invite role dropdown needs them immediately. So the clone is also exposed as a synchronous, **idempotent, locked** ensure that runs exactly where the roles are needed: a fast no-op once the queued job has run, an inline clone for the user who reached the screen first. Correctness stops depending on the cron tick. The queued clone-on-create stays for the common case. |

> No new trait on `User` and no new dependency. The host runs `php artisan migrate` (the `role_id` column) and re-publishes the tenancy Vue pages (`vendor:publish --tag=larafoundry-pages`) for the role dropdown; attribution, the clone ensure and the grant are automatic. Concrete domain roles stay in the host's permission config - the core ships only the mechanism.

### `v0.17.x` Legal / GDPR

The legal and GDPR layer, built as a seam rather than a checkbox. The headline is that the right to access and the right to be forgotten turn out to be the same shape: two mirrored provider registries, so every module wires data export and account erasure the same way. FREE core, so the code is open.

| Component | What it does |
|-----------|--------------|
| Legal pages | A super-admin editor for Terms, Privacy and Cookie policy, stored in the database per locale, **versioned**, served on a public `/legal/{slug}` route. The body is sanitized on save and on render through the same `HtmlSanitizer` the email editor uses; there is no variable rendering, so a legal page is static and safe. A fail-closed registry decides which slugs exist; an unpublished page 404s, so a placeholder default is never served as real legal text. |
| Consent | A cookie banner that ships **off** (the core sets only strictly-necessary cookies, which need no consent), a registration **Terms checkbox**, and a **re-accept gate** (`larafoundry.terms` middleware) that triggers when the published Terms version is bumped. `ConsentManager` is the single authority all of them read, so they never disagree. The gate is **fail-open**: nothing is enforced until a Terms page is published, so a fresh install is never locked out of a page that does not exist. |
| Data export (right to access) | A synchronous JSON download of everything the app holds about a user, assembled from every registered `ExportsUserDataProvider` (the core ships profile, sessions, settings and consent; modules add their own). Rate-limited against repeated dumps. |
| Account erasure (right to be forgotten) | Deleting an account is a **reversible soft-delete** that starts a grace clock; a super-admin can restore it during the window. A daily command (`larafoundry:purge-deleted-accounts`) then runs every registered `PurgesUserData`: the core **anonymises** the identity (never a hard DELETE, so foreign keys and legal records survive), modules erase what they own. It is **idempotent** via a `user_purged_at` stamp. The activity log is deliberately kept, anonymise the who, keep the what, as proof the erasure ran. |

> No new trait on `User` and no new dependency (the sanitizer arrived with `v0.16.x`). The host runs `php artisan migrate` and `vendor:publish --tag=larafoundry-pages`, mounts the `CookieConsentBanner` once in `app.js`, adds the `larafoundry.terms` middleware to its web group, and schedules `larafoundry:purge-deleted-accounts`. The operator "Legal pages" screen is wired into the core admin menu; the public legal and consent routes load from the package. Full reference: [docs/legal-gdpr.md](docs/legal-gdpr.md).

### `v0.16.x` Settings, profile and email templates

Three small service modules most SaaS apps rebuild by hand: a generic settings store, a self-service profile hub, and a database-backed editor for the wording of the core's transactional emails. They ship together, a host wires them in one pass, and they need no new trait on the user model.

| Component | What it does |
|-----------|--------------|
| Settings store | One generic key-value store with three scopes (app, company, user), driven by a **fail-closed** config registry: only declared keys can be read or written, each value cast and validated against its declared rule. Company settings are gated by RBAC and scoped to the active company, resolved server-side. App keys flagged `public` can be shared to the frontend. Backed by `larafoundry_settings`, cached per scope (no Redis). |
| Profile hub | `/profile`, one tabbed page over name and email, password, two-factor, PIN, sessions, avatar and UI preferences. Changing the email asks for the current password, resets verification and revokes other sessions. UI preferences go through an allowlist into `users.ui_settings` (the donor let any key into that column). Account deletion (owner-guarded) and a data-export registry are the seams for the GDPR phase. |
| Email template editor | A super-admin edits the subject and HTML body, per locale, of the core's verification / reset / welcome / invitation emails, stored in the database. The renderer is a **single-pass `{{token}}` replace, never Blade or eval**, so a stored template cannot execute code (no SSTI / RCE). On top: a strict allowed-variable check on save (422 otherwise), HTMLPurifier on the body, and a sandboxed-iframe preview. The core emails fall back to the static lang wording if a template is deactivated, so mail never breaks. |
| Notification mail channel | The in-app notification channel (`v0.14.x`) can now also send email, **opt-in per notification** under a master switch, so enabling the module never starts surprise mailings. |

> No new trait on `User`. The host runs `php artisan migrate` (the settings, email-template and `send_mail` migrations) and `vendor:publish --tag=larafoundry-pages`. The super-admin settings / email-template screens and the company-settings menu entry are wired into the core menus; the host links to the personal `/profile` and `/settings` from its own user menu, and optionally shares `Settings::publicSettings()` to the frontend. Full reference: [docs/settings-profile-email.md](docs/settings-profile-email.md).

### `v0.15.x` Support tickets (helpdesk)

A support channel between a host user and the platform operator: the customer opens a ticket, the operator answers from the console. Extracted from the production donor and rewritten as a self-contained module - no external ticket package.

| Component | What it does |
|-----------|--------------|
| User inbox | Every authenticated user reaches their own tickets from a header **Support** link (shipped in the core layout next to the bell). They open a ticket (title, message, categories), see the conversation and reply. The list is scoped to the caller, hides long-resolved tickets and is ordered by the support workflow. A **blocked** user can still reach support - it is their only channel to the operator. |
| Status workflow | Status is never picked by hand - it is derived from the action: a user-opened ticket is `wait-moderator`, an operator reply moves it to `wait-customer`, a user reply reopens it, and the operator closes it to `resolved`. |
| Operator console | The super-admin queue (filters + counters), one ticket's thread, reply, close, set priority and toggle categories/labels. Every operator mutation (status / priority / category / label) is written to the **activity log**; a reply or an operator-opened ticket pushes an **in-app notification** to the author (the phase 4.1 `NotificationService` seam). |
| Security | Message bodies render as **text, never `v-html`** (closing the donor's XSS hole); ticket creation and replies are **rate-limited**; the user side is authorized by ownership and the operator side by the super-admin gate. Categories and labels are config-driven slug lists stored as JSON - no extra tables. |

> The host adds `use HasTickets` to its user model (the `tickets()` relation). The user routes, the operator console and the **Support** header link / menu item ship in the core, so a host that renders through `LayoutSwitcher` gets the whole helpdesk with no frontend wiring. It optionally publishes `larafoundry-tickets-config` to extend the category/label lists.

### `v0.14.x` Notifications

An in-app notification centre plus super-admin broadcasts, delivered without realtime infrastructure (polling, not WebSockets, so no daemon is needed).

| Component | What it does |
|-----------|--------------|
| In-app inbox | Each user has their own notification centre: a bell with an unread badge (`NotificationBell`, polled on a light visibility-aware interval and refreshed on open) and a full inbox page. Every query is scoped to the caller, so a user only ever sees or marks their own. Titles and bodies render as **text, never `v-html`**, so a broadcast body cannot inject markup, and a notification's actions are reduced to internal GET links. |
| Super-admin broadcasts | The operator drafts a per-locale message with an audience filter (verification, recent activity, RBAC role) and an optional visibility window, then sends it. Sending is a **queued, chunked fan-out** (`SendBroadcastNotificationJob`, `insertOrIgnore`), not a synchronous mass-attach, so a large user base never blocks the request or double-delivers; the send route is rate-limited and the platform super-admin is excluded. |
| System notifications | A host's domain pushes a system notification through `NotificationService` (translation-key wording, the core's mail pattern): the seam that replaces the donor's per-event jobs. The broadcast-sent event is on the activity log. |
| Retention | `larafoundry:notifications-prune` clears read notifications past the retention window (the host schedules it, like the activity-log clean). |

> The host adds `use HasNotifications` to its user model (the `appNotifications()` relation, named to avoid clashing with Laravel's `Notifiable`); the bell and the operator-console "Broadcasts" screen ship in the core layouts and menu, so a host that renders through `LayoutSwitcher` gets both with no wiring. It optionally publishes `larafoundry-notifications-config` to extend the type registry, and the store lives in `larafoundry_notifications`, so it never collides with Laravel's reserved `notifications` table. Full reference: [docs/notifications.md](docs/notifications.md).

### `v0.13.x` QR cross-device login + presentation switch

Finishes the auth-entry phase: a WhatsApp-Web-style sign-in and a config switch for how the guest auth screens are surfaced.

| Component | What it does |
|-----------|--------------|
| QR cross-device login | The web (guest) side shows a QR (`QrLoginPanel`); a device already signed in scans it and approves the sign-in, and the web side polls until it is logged in. Extracted from the donor with its holes closed: the token is **SHA-256 hashed** in the DB (plaintext only in the QR), every endpoint is **rate-limited**, the session is regenerated before login, the code is single-use with a sliding TTL under an absolute cap, attempts are audited, and the scanner validates the decoded URL (same-origin + the verify path) before any request, closing the donor's blind-fetch SSRF. The super-admin is blocked from approving. |
| Sanctum API seam | The verify endpoint is **guard-agnostic** (`auth:sanctum`): a same-origin web request authenticates by session cookie today, and a future native app authenticates by Bearer token, through one controller. The core installs Sanctum and carries the `personal_access_tokens` table so a host gets the API guard with no extra setup. |
| Presentation switch | `auth.presentation` = `page` (default, unchanged) or `modal`: the same Login / Register / Forgot / Reset screens render either as a full page or as an overlay (`Modal` + `AuthScreen`), driven by one shared prop. An unknown value falls back to `page`, so the default surface never breaks. |

> QR login is gated by `LARAFOUNDRY_QR_ENABLED` (its routes are not registered when off). The host runs `php artisan migrate` (for `personal_access_tokens` and `sign_in_requests`), adds the `api` routing group + `statefulApi()` in `bootstrap/app.php`, and schedules `larafoundry:qr:prune` to clear spent codes.

### `v0.12.x` Auth entry modes (super-admin gate + PIN-lock)

Hardens how the platform operator enters the console, and adds an optional session PIN-lock for everyone. Three guards, all no-ops for the users they don't apply to.

| Component | What it does |
|-----------|--------------|
| Super-admin identity | The operator email (`security.super_admin.email`) is reserved: it cannot register or own a company (guards in `CreateNewUser` / `OAuthController` / `CreateCompanyAction`, resolved through one case-insensitive `VisitorStatus::isSuperAdminEmail()`). `RedirectSuperAdminToConsole` (`larafoundry.confine_admin`) keeps the operator inside `/admin`, redirecting them out of tenant routes. |
| OTP step-up gate | `EnsureAdminOtpVerified` (`larafoundry.admin.otp`) sits on the console: the operator must have confirmed Fortify 2FA (else → enrolment / 403) and clear a **fresh OTP once per session** before `/admin` opens. This makes "operator login is OTP-only" hold for **every** channel: an OAuth login skips Fortify's login challenge, but this gate catches it (and a stolen cookie) uniformly. Verification reuses Fortify's own provider (TOTP) + single-use recovery codes. |
| Session PIN-lock | `CheckPinLock` (`larafoundry.pin`) auto-locks an idle session and bounces it to a PIN-entry screen, a Telegram-style quick re-entry instead of a full re-login. PIN is per-user (bcrypt), lock state is per-session ("lock everywhere, unlock per-device"). PIN entry is **rate-limited** (per-session attempt counter + lockout window), the brute-force hole the donor lacked. |

> The host applies `larafoundry.confine_admin` + `larafoundry.pin` on its web group (see "Wiring the middleware"), points `security.super_admin.two_factor_setup_route` at its own 2FA-enrolment screen, and sets `LARAFOUNDRY_SUPER_ADMIN_EMAIL`. (QR cross-device login and the page/modal presentation switch landed in `v0.13.x`, above.)

### `v0.11.x` Admin Dashboard (operator console)

The operator console's landing screen, and the widget **seam** behind it. The dashboard is the exact mirror of the navigation engine: the backend builds and permission-filters a widget list from registered providers, and Vue renders each widget's component from a pluggable registry. The free core ships three widgets (users, companies, recent activity); a host or the paid add-on adds more without editing the core. It is revenue-agnostic on purpose.

| Component | What it does |
|-----------|--------------|
| `DashboardWidgetProviderInterface` + `DashboardWidget` + `DashboardBuilder` | The seam, 1:1 with the navigation `MenuProvider` / `MenuItem` / `MenuBuilder`. Providers contribute `DashboardWidget`s for a level (`admin`); the builder merges them, filters by RBAC (and `visible`), sorts by `order`, memoises per request and emits arrays. Widget titles are i18n **keys**, translated in Vue. The one difference from the menu seam: a provider receives the user, since a widget carries computed data. |
| `DashboardMetricsService` | The FREE metrics, kept out of the provider so the SQL is testable and cache-ready. Every figure is a constant-query aggregate (`SUM(CASE WHEN …)`), never a per-row classification, so the page is O(1) in the number of users / companies. Users (totals, recent sign-ups, verified / active / blocked), companies (totals + the `SubscriptionStatus` breakdown reproduced in SQL), activity (a 24h count + a compact recent feed). |
| `CoreMetricsWidgetProvider` | Registers the three free widgets (`core.users`, `core.companies`, `core.activity`) on the `admin` level. Behind the `larafoundry.admin` gate, so the widgets carry no per-item policy: the zone gate is the authority, like the admin menu. |
| Frontend widget registry | `dashboardWidgets` + `registerDashboardWidget(name, component)` exported from the package, plus the `UsersWidget` / `CompaniesWidget` / `ActivityWidget` / `UnknownWidget` Vue components and the `Admin/Dashboard` page. The page resolves each widget's component name through the registry and falls back to `UnknownWidget` (raw data) for a name it does not know, so a missing add-on registrar degrades gracefully instead of crashing the page. |

> Revenue is intentionally **not** here: the dashboard is revenue-agnostic and the revenue widget plugs in through the same seam from the paid `larafoundry-billing` add-on. The metrics are uncached in this release (a single-operator page over O(1) aggregates); the service is isolated so a `Cache::remember` can wrap it later without touching the seam.

### `v0.10.x` Admin Companies (operator console)

The second operator-console screen, built on the same pattern as Admin Users: a super-admin view of every company on the platform. It is read-only about money (the core stores no payment records) and read-only about subscriptions (managing a plan is the add-on's job). What it adds is a real company block the donor never had.

| Component | What it does |
|-----------|--------------|
| `CompanyController` | The super-admin company list (filterable, paginated), a read-only detail screen, and block / unblock. Behind the `larafoundry.admin` gate, with a second policy lock on the destructive block action. |
| `AdminCompaniesFilter` | Reflection-safe query filter (free-text over name/owner, country, created-at window, subscription status, block state). A status facet is computed as SQL from the billing columns so it pages in the database. |
| Company block + cascade | A super-admin block (`company_blocked_at`) that takes the whole team offline. Enforcement is at the single tenancy boundary (`EnsureActiveTenant`): a blocked company's members are denied the tenant screens regardless of role. The cascade is self-healing: a member of another, unblocked company is moved there automatically rather than stranded. Block columns are written server-side only (not mass-assignable), audited to the activity log, and accompanied by a tracked-session purge. |
| `SubscriptionStatus` | A read-only classifier (`on_trial` / `active` / `expiring` / `expired` / `never_activated`) over the billing columns, the single source the list badge and the filter share. With billing off (the default) every company reads as `never_activated` with access open: honest, not a bug. |

> Read-only about subscriptions on purpose: the screen reports state, it never changes a plan or period. Subscription management, payments and revenue metrics are the paid `larafoundry-billing` add-on. The admin dashboard is a later phase.

### `v0.9.x` Billing seam

The free core ships the *seam* for billing, not billing itself. It is the boundary the paid `larafoundry-billing` add-on plugs into: contracts for the payment gateway and plans, a driver manager in the Mail/Queue style, a region context, and a real `Company::hasAccess()` gate over subscription columns. No Stripe, Paddle or Cashier enters the free core's dependencies. With billing left off (the default) the core is a fully usable multi-tenant app with no paywall.

| Component | What it does |
|-----------|--------------|
| `Company::hasAccess()` | The access gate. With `billing.enabled` off (default) it is always true, so the free core never blocks. Turn it on and it reads real subscription state from the company's billing columns: a live trial or an active subscription grants access, anything else denies (fail-closed). The billing columns (`trial_ends_at`, `subscription_ends_at`, `plan_id`, ...) ship in the core migration but are not mass-assignable, so a tenant can never write its own subscription. |
| `PaymentGatewayInterface` + `PaymentGatewayManager` | The gateway driver seam, resolved by config like Mail or Queue. The free core registers one driver, `null`, which refuses every money operation loudly (no silent "success"). The add-on or a host registers real drivers via `extend()` and points `billing.gateway.default` at one. A host in a country Stripe/Paddle don't reach implements the contract for its local PSP. Webhook verification is part of the contract, not optional. |
| `PlanContract` + `PlanRepositoryContract` | A plan is an interface, not a hardcoded config array, so the source of plans (config, a table, the gateway catalogue) is the add-on's choice. `Company.plan_id` is a plain string identifier; the core knows no plans out of the box. |
| `RegionContext` | Country / currency / gateway routing, with a default that derives the country from the company's own column (server-side, never a client value). Per-country pricing and gateway routing are the add-on/host's job. |
| `EntitlementResolver` | The Billing↔RBAC hook: "does this plan entitle feature X", in the same slug vocabulary as RBAC permissions. Open for everything in the free core; the add-on makes it real. |

> This phase wires the gate but no caller yet: enabling billing makes `hasAccess()` answer correctly, and the future "subscription required" middleware / RBAC check will consult it without changing. Real payments, plans, promo codes, trials, the self-serve portal and revenue metrics are the paid add-on, not this seam. Honest about scope, as every release.

### `v0.8.x` File / media library

One seam for storing and serving files, so avatars, logos and (later) host documents all go through the same disk-agnostic path instead of hardcoding `public_path()`. Everything resolves through the `MediaStorage` contract, so the disk is configuration: point `larafoundry-media.disk` at `s3` and uploads move to the cloud with no code change. Image processing uses [intervention/image](https://image.intervention.io); the default placeholder avatar is rendered inline and needs no extension.

| Component | What it does |
|-----------|--------------|
| `MediaStorage` + `FileStorageManager` | The storage seam. `store()` writes to a configured disk with a generated uuid filename under a `YYYY/MM` shard (a client name can never steer the path), optionally producing named image variants. `url()`, `temporaryUrl()` and an idempotent `delete()` round it out. This is also the seam under a future polymorphic media library, so the avatar/logo call sites won't change when it lands. |
| `ImageProcessor` | Resize / crop through intervention, driven by config variants (`scaleDown` never upsizes, `cover` crops to exact size). The source is decoded once and reused across the original and every variant. The driver (`gd` / `imagick`) is configurable. |
| `AvatarGenerator` (initials) | A missing avatar renders as an initials placeholder, inline as an SVG data URI: no stored file, so it can never orphan, and no image extension required. `User::avatar_url` resolves the three shapes the column can hold: an external OAuth URL (as-is), a stored path (through the disk), or empty (the placeholder). Swap the contract to use Gravatar or anything else. |
| Private files | A non-public disk plus a short-lived, signed, auth-gated download route (`temporaryUrl()`), so a private file is never reachable by a raw, permanent path. Both the path and the disk are signed; the route re-validates the disk. The seam for host order/invoice documents. |
| Vue components | `UserAvatar`, `CompanyLogo` (image with an initials/initial fallback on empty or error), `FileUpload` and `ImageUpload` (file picker with a live preview), wired into the Admin Users table and the company switcher. |

> Polymorphic attachments (one model, many files) are intentionally **not** here yet. This is the contract they will stand on, kept thin so adding them later doesn't rewrite the avatar/logo call sites. Image-processing needs a GD or Imagick PHP extension at runtime (only when an image is actually uploaded, the placeholder avatar needs neither).

### `v0.7.x` Navigation engine + operator console (Admin Users)

A permission-aware navigation engine, and the first real screen of the operator console built on top of it. The menu is built **and filtered on the backend**, so links a user may not follow never reach the browser.

| Component | What it does |
|-----------|--------------|
| `MenuItem` + `MenuBuilder` + `MenuProviderInterface` | The engine. Providers contribute `MenuItem`s for a level (`admin` / `tenant`); the builder merges them, filters by RBAC (and `visible`), sorts by `order`, and emits the tree already pruned. Labels are i18n **keys**, translated in Vue, so a language switch re-paints the menu without a reload. Icons are names resolved to inline SVG (no published assets). The core ships an admin menu (Users, Activity log) and a tenant menu (Employees, Roles); a host adds its own via a provider. |
| `RbacPolicyChecker` | Bridges menu visibility to `hasPermissionTo($slug, $activeCompany)` (the same rule that guards the routes), and fails closed. |
| `LayoutSwitcher` + `AppLayout` | A persistent layout that picks the shell from a single backend signal (`nav_level`): super-admin gets the operator console, a tenant member gets the app shell with the filtered sidebar, everyone else gets the bare base shell. `MobileNav` reuses the same tree in a drawer. |
| Admin Users console | `Admin/Users/{Index,Edit,Create}` behind `larafoundry.admin`: list with filters (search / status / verification) + pagination, create / edit, block / unblock, soft-delete / restore. Blocking also invalidates the user's tracked sessions; every action is written to the activity log. Privilege/state columns are never mass-assigned. The resource omits social links (PII). |
| Impersonation | "Follow into a user", super-admin only. The policy refuses impersonating another admin, a blocked/deleted account, or yourself; take and leave are both audited and the session id is rotated on each identity swap. `leave` lives outside the admin gate (while impersonating you are not an admin). |

> Admin Companies (`v0.10.x`) and the Admin Dashboard (`v0.11.x`) are **not** in this phase: they sit closer to the billing data, so they ship after the billing seam to avoid a double extract.

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
| Clone-on-create | A queued, idempotent listener on `CompanyCreated` clones the template roles into every new company, also exposed as a synchronous locked ensure (`v0.18.x`) for flows that need the roles before the queue runs. |
| Role management | `RoleController` + `EmployeeAccessController` with a holder-check (you can only grant or assign what you already hold) and structural anti-IDOR scoping, plus the `Roles` Vue pages and a `PermissionsSelector`. |

> Super-admin is an identity flag resolved through `VisitorStatus`, never a role, so it can't be granted from a role-management screen.

### `v0.3.x` Multi-tenancy (companies / teams)

| Component | What it does |
|-----------|--------------|
| `BelongsToTenancy` (User) / `BelongsToTenant` (domain models) | Companies, ownership and membership on the user; an automatic, **fail-closed** tenant scope on domain models (no resolved tenant means no rows, never all rows). |
| `TenantScope` + resolvers | Session-based resolver for `teams` mode (active company tracked on the session row) and a `personal` mode where the user is their own tenant, behind one `TenantResolver` contract. |
| Company creation wizard | Multi-step company setup (no billing), `CompanySwitcher`, and the `SetActiveTenant` / `EnsureActiveTenant` middleware. |
| Invitations | Token invitations with a verified-email join guard, expiry, an optional company-scoped role granted on acceptance (`v0.18.x`), and IDOR-safe resend / delete scoped to the active company. |

### `v0.2.x` Authentication + Users

Authentication built on top of Laravel Fortify (the official, headless auth backend), with the pieces Fortify does not cover added around it.

| Component | What it does |
|-----------|--------------|
| `IsLaraFoundryUser` trait | Identity slice for the host's User model: name parts, phone, avatar, locale, OAuth provider linkage, blocking state, per-user 2FA (`TwoFactorAuthenticatable`), session tracking. Adds nothing about companies or roles; those arrive as their own traits in later phases. |
| OAuth (`OAuthController`) | Social sign-in via Socialite, provider-agnostic. Resolves strictly by provider identity first, then email, with an account-takeover guard: an OAuth login whose email matches an existing local account is refused by default rather than silently linked. The provider set is config-driven (`larafoundry.auth.oauth.providers`, shared to the frontend as the `auth_oauth` Inertia prop so buttons render from config with no Vue change). Default set is the providers Socialite ships built-in: `google`, `facebook`, `twitter` (OAuth 1.0a). Community providers (Apple, Microsoft, LinkedIn, Twitter/X OAuth 2.0) are a host concern — see below. |
| Login pipeline + Fortify actions | Hardened `CreateNewUser` / `ResetUserPassword` / `UpdateUserPassword` bound over Fortify's contracts, with a password policy stronger than the donor's. |
| `TrackSessionActivity` middleware | Records one tracked session row per device (fingerprint, IP, login method, last activity, last route) on every authenticated request. Powers an "active sessions" view and "log out other devices". |
| `EnsureAccountIsActive` middleware | Per-request gate that logs out blocked or soft-deleted accounts. |
| `VisitorStatus` | Identity-level status resolver (guest / authenticated / verified / blocked / deleted / admin) with a defence-in-depth admin check. |
| Localized auth mail | Verification and reset mail wording is owned by the core through `larafoundry::auth` translations, so it ships localized and follows the locale standard. Hosts override text and layout via publish. |
| Inertia + Vue auth pages | Login, Register, ForgotPassword, ResetPassword, VerifyEmail, ConfirmPassword, TwoFactorChallenge, TwoFactorSettings, UserBlocked, built on the form UI kit. Published into the host and rendered through Fortify's view resolvers. |

Two-factor (TOTP + recovery codes + QR enrolment) and passkeys come from Fortify out of the box.

**Enabling an OAuth provider (host side).** The core ships no credentials. To turn a built-in provider on in your host app: add the provider's credentials to `config/services.php` + `.env` (e.g. `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI`), make sure its slug is in `larafoundry.auth.oauth.providers`, and set `LARAFOUNDRY_OAUTH_ENABLED=true`. The button renders and the `OAuthController` accepts the slug automatically.

**Adding a community provider (Apple, Microsoft, …).** Some providers are not built into Socialite and come from a `socialiteproviders/*` package the host installs (they are listed under `suggest`, never required by the core). Since `v0.20.x` the core wires the driver for you — no manual event listener:

```bash
composer require socialiteproviders/apple
```

Add `apple` to `larafoundry.auth.oauth.providers`, add the credentials in `config/services.php` + `.env`, and the button + callback work like any built-in provider. Apple and Microsoft ship in the default `larafoundry.auth.oauth.community_drivers` map, so the core registers their `SocialiteWasCalled` driver automatically once the package is present — guarded by `class_exists`, so the core keeps no hard dependency on those packages. To add any other community package, map its slug to the package's `*ExtendSocialite` handler under `community_drivers` and list the slug in `providers`.

Note: `linkedin` and Twitter/X (OAuth 2.0) are deliberately not in the default map — Socialite 5 already ships a native `linkedin-openid` driver, and the OAuth-2 X package registers the driver name `twitter`, which collides with the built-in OAuth-1.0a `twitter`; resolve the slug (e.g. expose it as `x`) before mapping it.

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

> **Integrating into an existing app?** Read the end-to-end walkthrough first — installation, the User model on an existing `users` table, `personal` mode (no companies), login-only, Google OAuth + QR, super-admin, and the Inertia/Vite frontend wiring: **[docs/integrating-into-an-existing-app.md](docs/integrating-into-an-existing-app.md)**.

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
use Dmitryisaenko\LaraFoundry\Http\Middleware\RedirectSuperAdminToConsole; // v0.12
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\CheckPinLock;          // v0.12
use App\Http\Middleware\HandleInertiaRequests; // extends the core one

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        HandleAppearance::class,
        SetLocale::class,
        HandleInertiaRequests::class,
        TrackSessionActivity::class,
        EnsureAccountIsActive::class,
        // v0.12: confine the super-admin to /admin and enforce the session
        // PIN-lock. Both no-op for non-admins / users without a PIN and skip
        // their own routes, so they sit safely on the global web group.
        RedirectSuperAdminToConsole::class,
        CheckPinLock::class,
    ]);
})
```

> "Log out other devices" evicts remote sessions immediately on the `database` session driver. On other drivers the framework session lives outside the package's reach, so that feature needs the database driver.

> The operator console's OTP step-up (`larafoundry.admin.otp`) is wired onto the admin routes by the package itself. The host only points `security.super_admin.two_factor_setup_route` at its 2FA-enrolment screen so an un-enrolled operator is sent there.

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

### For AI coding agents

Since `v0.21.x` the package ships its own AI dev-context at the repo root: `AGENTS.md` (the golden rules and layout for working *on* the engine) and `CLAUDE.md` (a short pointer to it). The [integration guide](docs/integrating-into-an-existing-app.md) is the companion for *consuming* the core, written to be handed to a coding agent. To make every agent in your host project aware of it automatically, add this to your host app's root `CLAUDE.md` / `AGENTS.md` (agents do not read `vendor/` on their own):

```markdown
This app is built on `dmitryisaenko/larafoundry`. Before touching the core,
read `vendor/dmitryisaenko/larafoundry/docs/integrating-into-an-existing-app.md`.
Never edit the package in `vendor/`; extend it only through its documented seams.
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
| 1.4 | Auth entry modes (super-admin OTP gate, session PIN-lock, QR cross-device login, page/modal presentation switch) | ✅ Shipped (`v0.12.x`–`v0.13.x`) |
| 2.1 | [Activity logging](docs/modules/logging.md) (platform audit) | ✅ Shipped (`v0.5.x`) |
| 2.2 | [Multilanguage](docs/modules/multilanguage.md) (i18n, language switcher) | ✅ Shipped (`v0.6.x`) |
| 2.3 | [Navigation](docs/modules/navigation.md) engine + [Admin users](docs/modules/admin_users.md) console (+ impersonation) | ✅ Shipped (`v0.7.x`) |
| 2.4 | File / media library (storage seam, image variants, default avatars, private files) | ✅ Shipped (`v0.8.x`) |
| 3.1 | [Billing](docs/modules/payments.md) seam (gateway contract + driver manager, subscription columns, real `hasAccess` gate, region context) | ✅ Shipped (`v0.9.x`) |
| 3.3 | [Admin companies](docs/modules/admin_companies.md) console (company list + filters, read-only subscription status, super-admin block cascade) | ✅ Shipped (`v0.10.x`) |
| 3.4 | Admin dashboard (operator-console landing screen, pluggable widget seam, free user / company / activity widgets) | ✅ Shipped (`v0.11.x`) |
| 3.x | Billing add-on (`larafoundry-billing`: real payments via Stripe / Paddle, plans, promo codes, trials, subscription management, revenue metrics) | 💳 Available (paid add-on, see [larafoundry.com](https://larafoundry.com)) |
| 4.1 | [Notifications](docs/modules/notifications.md) (in-app inbox + bell, super-admin broadcasts, queued fan-out, retention) | ✅ Shipped (`v0.14.x`) |
| 4.2 | [Tickets](docs/modules/tickets.md) / helpdesk (user inbox + operator console, status workflow, in-app notifications, audit) | ✅ Shipped (`v0.15.x`) |
| 5.1 | [Settings, profile and email templates](docs/settings-profile-email.md) (key-value settings store, profile hub, super-admin email editor) | ✅ Shipped (`v0.16.x`) |
| 5.3 | [Legal / GDPR](docs/legal-gdpr.md) (legal pages editor, cookie / terms consent, data export, grace-period account erasure) | ✅ Shipped (`v0.17.x`) |
| 5.5 | AI dev-context (`AGENTS.md` / `CLAUDE.md` + [existing-app integration guide](docs/integrating-into-an-existing-app.md)) | ✅ Shipped (`v0.21.x`) |
| 5.x | [Monetization upsell stubs](docs/monetization-stubs.md) (reserved Payments / Affiliates / Promo console slots the paid add-on lands on) | ✅ Shipped (`v0.31.x`) |
| 5.x | [SEO kit](docs/seo.md) (server-rendered `<head>` meta, sitemap provider registry + `sitemap.xml` / `robots.txt`, hreflang, thin OG-image seam) | ✅ Shipped (`v0.32.x`) |
| 5.x | [Onboarding checklist](docs/onboarding.md) (auto-detected getting-started steps, per-user dismiss, provider registry) | ✅ Shipped (`v0.33.x`) |
| 4.x / 5.x | Feature voting, documentation | 📋 Planned |

Build-in-public write-ups for each shipped phase are on [Dev.to](https://dev.to/d_isaenko_dev).

---

## Quality

- **Pest** on every piece of the core: 796 tests across foundation, auth, tenancy, RBAC, the activity log, multilanguage, the navigation/operator-console layer, the file/media library, the billing seam, the admin-companies console, the admin dashboard, the auth-entry layer (super-admin OTP gate, session PIN-lock, QR cross-device login), the in-app notification centre, the support helpdesk, the settings / profile / email-template modules (incl. the consolidated profile hub and per-user date format), the legal / GDPR layer (editable legal pages, the fail-open Terms gate, consent, data export and the grace-period account-erasure cron), optional role-at-invite, the admin-access security alert (the unified failure event, the three config axes of its policy, and the per-source gating to the super-admin), and the config-driven OAuth providers with community-driver auto-registration, many of which caught real bugs during extraction and review (a broken default-locale fallback, a mass-method-invocation gap in the filter dispatcher, a fail-open tenant scope, a privilege-escalation hole in delegated permission grants, a misrecorded audit subject, an open redirect on the language switch, the donor's wide-open impersonation now policy-gated and audited, a media-default that upsized small avatars into blurry thumbnails, an empty-string gateway config that would have thrown on every access check, a company-block cascade that would have looped a single-company member until it was made self-healing, a QR sign-in token that the donor stored in plaintext and leaked into the audit log, an email-template editor built so a database-stored template can never execute code, and a tenant-scoped invite-role rule made fail-closed so a null company id can't fall through to `whereNull` and match a global role) with the billing access gate pinned fail-closed both ways and the settings store fail-closed to its registry.
- **Frontend tests** with Vitest + Vue Test Utils on the UI kit, pages, navigation and media components, including a stored-XSS guard on the activity-log table.
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
