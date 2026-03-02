```markdown
# LaraFoundry

> A production-proven SaaS engine for Laravel. Build your next SaaS 10x faster.

LaraFoundry is a modular SaaS foundation extracted from [Kohana.io](https://kohana.io) — a real, production CRM/ERP system. It provides everything you need to launch a SaaS product without rebuilding the boring parts.

**Tech Stack:** Laravel 12 · PHP 8.3 · Vue 3 · Inertia v2 · Tailwind CSS

---

## 📦 Modules

| Module | Status | Description |
|--------|--------|-------------|
| [Registration](docs/modules/registration.md) | ✅ Ready | Multi-provider auth, OAuth2, avatars, session tracking, logging |
| Roles & Permissions | 🔧 In Progress | Spatie-based RBAC with team scoping |
| Multi-tenancy | 🔧 In Progress | Company-based tenancy with team management |
| Activity Logging | 📋 Planned | Full audit trail system |
| Subscriptions | 📋 Planned | Stripe/Paddle billing integration |

---

## 🔄 Latest Updates

### February 2026 — Registration Module

The first module is ready: **Registration**.

What's included:
- **Multi-provider authentication**: Email/password + OAuth2 (Google, Facebook, Twitter) via Laravel Socialite
- **Smart avatar system**: Automatic Gravatar detection with generated initials fallback (15 color themes)
- **Session & device tracking**: Full device fingerprinting — browser, OS, device type, IP, geo location
- **Auth event logging**: 7 authentication events logged automatically via Spatie Activity Log
- **Team onboarding**: Company invitation system with auto-acceptance on registration
- **Email verification**: Signed links with customizable email templates

→ [Read the full module documentation](docs/modules/registration.md)

---

## 🚀 Getting Started

> LaraFoundry is currently in active development. Join the waitlist to be notified when it's ready.

**Waitlist:** [larafoundry.com](https://larafoundry.com)

---

## 🛠️ Built With

- [Laravel 12](https://laravel.com) — PHP framework
- [Vue 3](https://vuejs.org) — Frontend framework
- [Inertia.js v2](https://inertiajs.com) — SPA without the complexity
- [Laravel Socialite](https://laravel.com/docs/socialite) — OAuth authentication
- [Spatie Activity Log](https://spatie.be/docs/laravel-activitylog) — Activity logging
- [Laravolt Avatar](https://github.com/laravolt/avatar) — Avatar generation
- [Intervention Image](https://image.intervention.io) — Image processing

---

## 📝 License

LaraFoundry will be released under the MIT License.

---

## 👤 Author

**Dmitry Isaenko** — Full-stack Laravel developer building SaaS tools.

- Twitter/X: [@d_isaenko_dev](https://twitter.com/d_isaenko_dev)
- LinkedIn: [Dmitry Isaenko](https://linkedin.com/in/d-isaenko-dev)
- Dev.to: [@d_isaenko_dev](https://dev.to/d_isaenko_dev)
```
