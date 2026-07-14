# LaraFoundry documentation

This folder is the reference for **host integrators**: how to wire each module of
the core into your Laravel host app. Every page follows the same shape so you
always know where to look: Install, Configuration, Usage, API reference, Security
notes, Testing.

You do not need to read the core's source to use it. You need to know two things:
what a module gives you, and which seam you plug into. This index covers the
second; each module page covers the first.

> **Start here:** [Integrating LaraFoundry into an existing Laravel app](integrating-into-an-existing-app.md) — the end-to-end walkthrough (install, the User model on an existing `users` table, `personal` mode without companies, login-only, Google OAuth + QR, super-admin, and the Inertia/Vite frontend wiring).

> **Working with a coding agent?** Since `v0.21.x` the package ships its own AI dev-context at the repo root: `AGENTS.md` (the rules for building the engine) and `CLAUDE.md` (a pointer to it). Point your agent at `vendor/dmitryisaenko/larafoundry/docs/integrating-into-an-existing-app.md` to consume the core, and at `AGENTS.md` only if it is working *on* the package. See "For AI coding agents" in the project [readme](../readme.md).

## How the core extends your host

The core never asks you to edit it. Instead it exposes a small, fixed set of
seams, and every module, no matter how many there are, plugs in through some
combination of these. Learn them once and a new module is never a surprise:

1. **Model traits.** You mix a core trait into your own model to gain a feature.
   Your `User` already carries `BelongsToTenancy` (multi-tenancy),
   `HasRolesAndPermissions` (RBAC) and `IsLaraFoundryUser` (identity); add
   `HasNotifications` for the inbox, `HasTickets` for support. One `use` per
   feature.
2. **Package install and migrations.** The core arrives as a Composer package and
   ships its own tables. You run `php artisan migrate`; you never write the core's
   migrations.
3. **Provider registries.** You add your own screens to the core's menu or
   dashboard with one line in `AppServiceProvider`, by registering a
   `MenuProvider` or `DashboardWidgetProvider`. The core builds and filters; you
   contribute.
4. **Config registries.** You publish a `config/larafoundry-*.php` file and edit a
   list to declare your own notification types, activity-log events, or plans.
   Declarative, no code.
5. **Published pages and barrel components.** `vendor:publish --tag=larafoundry-pages`
   copies the Inertia pages into your host; ready-made Vue components
   (`NotificationBell`, `LayoutSwitcher`, switchers) are imported from the
   `@dmitryisaenko/larafoundry` barrel.
6. **Core services called from your domain.** When something happens in your
   business logic, you call a core service: for example
   `NotificationService::system(...)` to notify users. This is the glue between
   your domain and a core feature.

## Modules

Every module below has an accurate reference page, in the shape above. The early
planning drafts under `modules/` are kept at their original URLs (external links
point at them) and each banner now points forward to its accurate page.

| Module | Phase | Reference |
|--------|-------|-----------|
| Multi-tenancy | 1.2 | [multi-tenancy.md](multi-tenancy.md) |
| Admin-access security alert | 1.4 | [admin-access-alert.md](admin-access-alert.md) |
| Notifications | 4.1 | [notifications.md](notifications.md) |
| Tickets and helpdesk | 4.2 | [tickets.md](tickets.md) |
| Settings, profile and email templates | 5.1 | [settings-profile-email.md](settings-profile-email.md) |
| Legal pages and GDPR | 5.3 | [legal-gdpr.md](legal-gdpr.md) |
| Authentication and sessions | 1.1 | [authentication.md](authentication.md) |
| Roles and permissions | 1.3 | [roles-permissions.md](roles-permissions.md) |
| Activity log | 2.1 | [activity-log.md](activity-log.md) |
| Multilanguage | 2.2 | [multilanguage.md](multilanguage.md) |
| Navigation and operator console | 2.3 | [navigation.md](navigation.md) |
| Admin: users | 2.3 | [admin-users.md](admin-users.md) |
| Admin: companies | 3.3 | [admin-companies.md](admin-companies.md) |
| Files and media | 2.4 | [media.md](media.md) |
| Billing seam (free) | 3.1 | [billing-seam.md](billing-seam.md) |
| Monetization upsell stubs | 5.x | [monetization-stubs.md](monetization-stubs.md) |
| SEO kit | 5.x | [seo.md](seo.md) |
| Onboarding checklist | 5.x | [onboarding.md](onboarding.md) |
| Vue frontend | packaging | early draft: [modules/vue_frontend.md](modules/vue_frontend.md) |

The paid `larafoundry-billing` add-on documents its own usage (Stripe and Paddle
setup, entitlements, promo codes) in its own private repository. The public core
documents only the free billing seam those drivers plug into.

> The `modules/` pages are early planning drafts from before the build and may not
> match the shipped package. External links point at them, so they stay in place;
> as each accurate page lands, its draft's banner points here.
