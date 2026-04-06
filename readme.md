# LaraFoundry

> A production-proven SaaS engine for Laravel. Build your next SaaS 10x faster.

LaraFoundry is a modular SaaS foundation extracted from [Kohana.io](https://kohana.io) - a real, production CRM/ERP system. It provides everything you need to launch a SaaS product without rebuilding the boring parts.

**Tech Stack:** Laravel 12 · PHP 8.3 · Vue 3 · Inertia v2 · Tailwind CSS

---

## 📦 Modules

| Module | Status | Description |
|--------|--------|-------------|
| [Registration](docs/modules/registration.md) | ✅ Ready | Multi-provider auth, OAuth2, avatars, session tracking, logging |
| [Authentication](docs/modules/authentication.md) | ✅ Ready | Email/Password, OAuth, QR Code Login, PIN Code Lock, 2FA (TOTP), IP Whitelisting |
| [Multi-tenancy](docs/modules/multi_tenancy.md) | ✅ Ready | Company-based tenancy with team management |
| [Activity Logging](docs/modules/logging.md) | ✅ Ready | Full audit trail system |
| [Multilanguage (i18n)](docs/modules/multilanguage.md) | ✅ Ready | Production-grade internationalization system with automatic language detection and seamless Laravel-to-Vue translation pipeline. |
| [Navigation & Menu System](docs/modules/navigation.md) | ✅ Ready  | Dynamic, permission-aware navigation that builds menus per request based on user type, company role, and granted permissions. |
| Vue Frontend (Inertia v2 + Vue 3) | 📋 Planned | Server-driven frontend architecture with dynamic layout switching, overlay management, pagination, filters, and a hybrid modal system - all without Vuex or Pinia. |
| Traits & Middlewares | 📋 Planned | The invisible backbone of a multi-tenant SaaS: 11 middlewares in strict execution order and 6 custom traits for business logic reuse. |
| Admin Users | 📋 Planned | The command center for managing users in a multi-tenant SaaS: CRUD, banning with cascade, impersonation, activity logging, and auto-discovery filters. |
| Admin Companies | 📋 Planned | The financial control center for managing companies in a multi-tenant SaaS: subscription tracking, payment history, ban cascade, automated expiry notifications, and context-aware blocked pages. |
| Notifications | 📋 Planned | Dual notification system supporting admin broadcasts and automated system notifications. |
| Tickets (Support System) | 📋 Planned | Dual-interface support ticket system where users create and track tickets, while admins manage, categorize, and respond through a separate admin panel. |
| Payments & Promo Codes | 📋 Planned | Stripe/Paddle billing integration. Admin payment dashboard with multi-currency revenue tracking, flexible promo code system, and full discount audit trail. |

---

## 🔄 Latest Updates

### February 2026 - Registration Module

What's included:
- **Multi-provider authentication**: Email/password + OAuth2 (Google, Facebook, Twitter) via Laravel Socialite
- **Smart avatar system**: Automatic Gravatar detection with generated initials fallback (15 color themes)
- **Session & device tracking**: Full device fingerprinting - browser, OS, device type, IP, geo location
- **Auth event logging**: 7 authentication events logged automatically via Spatie Activity Log
- **Team onboarding**: Company invitation system with auto-acceptance on registration
- **Email verification**: Signed links with customizable email templates

> [Detailed registration module documentation →](docs/modules/registration.md)


### March 2026 - Authentication Module

LaraFoundry ships with a production-grade, multi-method authentication system - far beyond the standard login/register flow.

**6 Authentication Methods:**
- **Email/Password** - Rate-limited login (5 attempts), session regeneration, device fingerprinting
- **OAuth** - Google, Facebook, Twitter via Laravel Socialite v5 with auto-verified emails
- **QR Code Login** - WhatsApp-style cross-device authentication with UUID tokens and encrypted verification
- **PIN Code Lock** - 4-digit screen lock for shared workstations with configurable inactivity timeout
- **2FA (TOTP)** - Google Authenticator for admin accounts, enforced via middleware
- **IP Whitelisting** - Admin access restricted to configured IPs with instant force-logout

**Security Features:**
- Real-time admin alert system (Email + Telegram) on every failed login attempt
- Session tracking with full device info (type, name, OS, browser, IP)
- Visitor status system with 6 states (guest, auth, admin, blocked, deleted, forcelogout)
- BCrypt-hashed PINs and passwords
- Signed URLs for email verification
- Per-session independent PIN lock with DB-persisted state

**Middleware Stack:**
- `CheckPinLockMiddleware` - Auto-locks inactive sessions
- `Require2FA` - Enforces 2FA on all admin routes
- `AdminAccess` - Gates admin panel access
- `GetVisitorStatusAction` - Centralized user role determination

> [Detailed authentication module documentation →](docs/modules/authentication.md)


### March 2026 - Multi-Tenancy & Authorization

LaraFoundry provides a complete multi-tenancy system with automatic data isolation, config-driven permissions, and a 5-level authorization hierarchy - purpose-built for SaaS where company owners manage their own teams.

**Data Isolation:**
- **BelongsToCompany trait** - Automatic Eloquent global scope filtering by active company. One trait per model, zero chance of cross-tenant data leaks
- **Admin bypass** - `scopeForAdmin()` to query across all companies
- **Company-scoped queries** - `scopeForCompany($id)` for cross-tenant reports

**Permission System (100+ permissions, 20+ modules):**
- **Config-driven** - All permissions defined in `config/roles-and-permissions.php`, auto-registered as Gates
- **Dedicated Gate classes** - 8 module-specific Gate classes for complex business logic (CompanyGates, EmployeeGates, RoleGates, ContragentGates, WarehouseGates, ProductionGates...)
- **5-level hierarchy** - Super admin > Owner > Revoked > Individual grant > Role-based
- **Permission overrides** - Grant or revoke individual permissions per user, overriding role defaults
- **Artisan sync** - `php artisan permissions:sync` with `--fresh` and `--cleanup` flags

**Role Management:**
- **5 role templates** auto-cloned to every new company (Manager, Accountant, Storekeeper, Logistician, Worker)
- **Custom roles** - Company owners create, edit, and delete roles from the UI
- **Multiple roles per user** - Assign any combination of roles to employees
- **Company-scoped** - Same role slug can have different permissions in different companies

**Middleware Stack:**
- `SetActiveCompanyMiddleware` - Auto-resolves tenant context with ownership priority
- `CheckAccessMiddleware` - User/owner ban status + subscription/trial checks
- `CheckCompanyAccess` - Owner-only route protection

**Navigation & Routing:**
- **Permission-aware menu** - Menu items filtered by `checkUserAndCompanyPolicy()`, if you can't access it - you don't see it
- **First Allowed Route (FAR)** - Smart redirects instead of 403 error pages
- **User-configurable landing page** - Each user sets their default page per company, auto-resets if permissions change

**Test Coverage:** 19 test files covering permission hierarchy, cross-company isolation, Gate authorization, menu visibility, middleware chain, role CRUD, and edge cases.

> [Detailed module documentation ->](docs/modules/multi_tenancy.md)

### March 2026 - Activity Logging & Monitoring

Production-grade activity logging system with event-driven architecture, device fingerprinting, and async geolocation.

**Key features:**
- 60+ events mapped to structured activity logs via a single ServiceProvider
- Zero manual log calls - fire an event, logging happens automatically
- Device fingerprinting (browser, OS, device type) via `jenssegers/agent`
- Async IP geolocation with queued jobs (cached 24h, graceful fallback)
- Custom Activity model extending Spatie with 20+ queryable fields
- Multi-channel admin notifications (Email + Telegram) for critical events
- Admin UI with time-range filtering (Vue 3 + Inertia)
- Three-layer observability: business logs, Telescope (dev), file logs (Log Viewer)
- Monolog split channels: daily (14 days) + critical (30 days)
- Full test coverage with Pest PHP

**Packages:** `spatie/laravel-activitylog`, `jenssegers/agent`, `opcodesio/log-viewer`, `laravel/telescope`

> [Detailed module documentation ->](docs/modules/logging.md)

### March 2026 - Multilanguage (i18n)

Production-grade internationalization system with automatic language detection and seamless Laravel-to-Vue translation pipeline.

- **Automatic locale detection** - 5-step fallback chain: user preference -> session -> browser Accept-Language -> IP geolocation -> default
- **4 languages ready** - English, Ukrainian, Polish, German (easily extensible)
- **Zero-config frontend** - Translations passed via Inertia shared props, global `t()` function in Vue
- **Separate auth/guest flows** - Authenticated users persist to DB, guests to long-lived cookies
- **Content translation API** - Pluggable layer with DeepL and Google Translate providers
- **IP geolocation** - Country-based language detection via ip-api.com
- **1700+ translation strings** - Production-ready Ukrainian locale included
- **Tested** - Full Pest test coverage for all detection paths and edge cases

> [Detailed module documentation →](docs/modules/multilanguage.md)

### April 2026 - Navigation & Menu System

Dynamic, permission-aware navigation that builds menus per request based on user type, company role, and granted permissions.

- **Dynamic menu building** - LayoutDataService constructs 4 navigation contexts (desktop top/bottom, mobile, sidebar) on every request
- **Permission filtering** - Every menu item checked via `checkUserAndCompanyPolicy()` with support for admin, owner, and employee roles
- **Sub-menu routing** - Each module maps to a default sub-page with granular permission checks at sub-route level
- **First Allowed Route (FAR)** - Zero 403 pages; users are always redirected to their first accessible page
- **User-configurable defaults** - Set preferred landing page per company in profile settings
- **Desktop** - Two-tier header (module tabs + sub-page tabs) with server-computed active states
- **Mobile** - Hamburger menu with slide-out pullout, collapsible parent/child sections, and default page selector
- **Tested** - Pest tests for all user types, permission combinations, and edge cases

> [Detailed documentation →](docs/modules/navigation.md)

---

## 🚀 Getting Started

> LaraFoundry is currently in active development. Join the waitlist to be notified when it's ready.

**Waitlist:** [larafoundry.com](https://larafoundry.com)

---

## 🛠️ Built With

- [Laravel 12](https://laravel.com) - PHP framework
- [Vue 3](https://vuejs.org) - Frontend framework
- [Inertia.js v2](https://inertiajs.com) - SPA without the complexity
- [Laravel Socialite](https://laravel.com/docs/socialite) - OAuth authentication
- [Spatie Activity Log](https://spatie.be/docs/laravel-activitylog) - Activity logging
- [Laravolt Avatar](https://github.com/laravolt/avatar) - Avatar generation
- [Intervention Image](https://image.intervention.io) - Image processing

---

## 📝 License

LaraFoundry will be released under the MIT License.

---

## 👤 Author

**Dmitry Isaenko** - Full-stack Laravel developer building SaaS tools.

- Twitter/X: [@d_isaenko_dev](https://twitter.com/d_isaenko_dev)
- LinkedIn: [Dmitry Isaenko](https://linkedin.com/in/d-isaenko-dev)
- Dev.to: [@d_isaenko_dev](https://dev.to/d_isaenko_dev)
