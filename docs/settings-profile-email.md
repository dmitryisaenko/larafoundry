# Settings, profile and email templates

Phase 5.1 ships three small service modules that most SaaS apps rebuild by hand. They are documented together because a host wires them in one pass:

- **Settings**: one generic key-value store with three scopes (app, company, user), driven by a fail-closed config registry.
- **Profile**: a self-service profile hub (name and email, password, two-factor, PIN, sessions, avatar, UI preferences) with proper email-change hygiene and the seams for account deletion and data export.
- **Email templates**: a super-admin editor for the wording of the core's transactional emails, stored in the database and rendered without Blade or eval.

This is the accurate reference for the shipped package (core `v0.16.x`).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)

## Install

All three modules ship with the core package; there is nothing extra to require and no trait to add to your `User` (the identity columns they use, `ui_settings` and `avatar`, are already part of the core user migration). A host opts in by:

1. Running the migrations the package contributes. They load automatically:
   - `larafoundry_settings` (the key-value store)
   - `larafoundry_email_templates` (the editable email overrides)
   - a `send_mail` flag added to `larafoundry_notifications` (the opt-in notification mail channel)
2. Publishing the Inertia pages so the profile hub, the settings screens and the email-template editor resolve in the host:

```bash
php artisan migrate
php artisan vendor:publish --tag=larafoundry-pages
```

The email-template editor pulls in [ezyang/htmlpurifier](https://github.com/ezyang/htmlpurifier) as a dependency (it sanitizes the HTML body). `composer require dmitryisaenko/larafoundry` brings it along; nothing to install by hand.

The profile and account-settings menu entries are personal, so the core deliberately does not put them in the tenant sidebar (that would break the "a bare employee sees an empty menu" invariant). The host links to `/profile` and `/settings` from wherever it surfaces the signed-in user (a header dropdown, the dashboard). The company-settings and the super-admin settings / email-template screens are wired into the core's tenant and admin menus automatically.

To surface the public app settings to the frontend (for example, whether sign-ups are open), share them from the host's Inertia middleware:

```php
use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;

// app/Http/Middleware/HandleInertiaRequests.php, inside share()
'public_settings' => fn (): array => Settings::publicSettings(),
```

## Configuration

### Settings registry (in `config/larafoundry.php`)

Settings are nested in the core config under the `settings` key, so they merge in automatically (publish `larafoundry-config` only if you want to extend the list). Every key is declared once; this registry is the single source of truth, and only declared keys can ever be read or written:

```php
'settings' => [
    'support_email'   => ['scope' => 'app', 'label' => 'Support email', 'type' => 'string',  'default' => null, 'validation' => ['nullable', 'email', 'max:255'], 'public' => true],
    'signups_enabled' => ['scope' => 'app', 'label' => 'Allow new sign-ups', 'type' => 'boolean', 'default' => true, 'validation' => ['boolean'], 'public' => true],
    'timezone'        => ['scope' => 'company', 'label' => 'Time zone', 'type' => 'string', 'default' => 'UTC', 'validation' => ['string', 'timezone']],
    'email_notifications' => ['scope' => 'user', 'label' => 'Email notifications', 'type' => 'boolean', 'default' => true, 'validation' => ['boolean']],
],
```

| Key field | Meaning |
|-----------|---------|
| `scope` | `app` (platform, super-admin only), `company` (the active company, gated by RBAC), or `user` (the caller's own). |
| `type` | `boolean` / `integer` / `float` / `string` / `array`. The stored value is cast to this on read, never trusted raw. |
| `default` | Returned when nothing is stored yet. |
| `validation` | A Laravel rule the value must pass on write. |
| `in` | Optional enum of allowed values (also drives a select in the UI). |
| `public` | Optional, app scope only. Marks a value safe to expose to the frontend through `Settings::publicSettings()`. |
| `form` | Optional, default true. `false` keeps a key in the store but out of the self-service form (the consent flags for the upcoming GDPR phase use this). |

To add your own settings, publish `larafoundry-config` and extend the list.

### Profile UI preferences (in `config/larafoundry.php`)

User UI preferences are written to the `users.ui_settings` JSON column through an allowlist, never as free-form keys:

```php
'profile' => [
    'ui_settings' => [
        'theme'             => ['type' => 'string',  'default' => 'system', 'in' => ['light', 'dark', 'system']],
        'sidebar_collapsed' => ['type' => 'boolean', 'default' => false],
        'table_density'     => ['type' => 'string',  'default' => 'comfortable', 'in' => ['comfortable', 'compact']],
        // per-user date format (v0.21.x). 'auto' derives the order from the
        // active locale (en -> month-first, uk -> day-first); the explicit
        // values override it regardless of language (format is not language).
        'date_format'       => ['type' => 'string',  'default' => 'auto', 'in' => ['auto', 'dmy', 'mdy', 'iso']],
    ],
],
```

A host adds its own preferences here. Only declared keys are stored, each cast to its declared type, so a client can never stuff arbitrary data into the column.

> **Updates since `v0.16.x`.** Two changes worth noting if you wired this module before `v0.21`:
> - **Profile hub consolidation (`v0.21.0`).** The separate account-settings screen folded into the one `/profile` hub, so name/email, password, two-factor, PIN, sessions, avatar and preferences now live on a single tabbed page. If your user menu linked to a standalone account screen, point it at `/profile`.
> - **Per-user date format (`v0.21.0`).** The `date_format` preference above lets each user choose day-first, month-first or ISO independently of the interface language. The shared `useDateFormat()` composable reads it everywhere, so host pages that render dates through it follow the user's choice automatically.
> - **Company time zone as a dropdown (`v0.21.4`).** The `timezone` company setting now ships `'in' => timezone_identifiers_list()`, so the form renders a searchable select instead of a free-text field while the `timezone` validation rule still guards the value.

### Email templates (in `config/larafoundry-email.php`)

The core ships four editable templates. The config registry declares each template's code and the variables it is allowed to use, plus the default subject and body per locale; the database holds the operator's overrides on top. Publish `larafoundry-email-config` only to change the defaults or the allowed-variable list:

```php
'test_email' => ['throttle' => '5,1'],
'templates' => [
    'welcome_email'      => ['variables' => ['app_name', 'name'], 'subject' => [...], 'body_html' => [...], 'body_text' => [...]],
    'email_verification' => ['variables' => ['app_name', 'name', 'url'], ...],
    'password_reset'     => ['variables' => ['app_name', 'name', 'url'], ...],
    'company_invitation' => ['variables' => ['app_name', 'company_name', 'inviter_name', 'url'], ...],
],
```

### Notification mail channel (in `config/larafoundry-notifications.php`)

Phase 5.1 makes the in-app notification channel able to also send email, off by default per notification. The master switch is the `channels` key (it already includes `mail`); a notification only emails when it opts in AND the channel is enabled:

```php
'channels' => ['database', 'mail'],
```

This config is not published by a host by default, so the whole file (including this key) comes from the package. See [notifications.md](notifications.md).

## Usage

### Reading and writing settings from your domain

```php
use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;

Settings::get('signups_enabled');                  // app scope, the sentinel id is implicit
Settings::get('timezone', $company->id);           // company scope
Settings::get('email_notifications', $user->id);   // user scope

Settings::set('timezone', 'Europe/Kyiv', $company->id);
```

`get()` is fail-closed: an unregistered key returns its `default` (or your fallback), never a raw row. `set()` validates the value against the key's rule, casts it, and busts a per-scope cache (`Cache::rememberForever`, so it works on file or database cache with no Redis).

The self-service screens are already wired: a user edits their own settings at `/settings`, an authorised company member edits the active company's settings at `/settings/company` (gated by the `company.settings.view` / `company.settings.update` RBAC permissions, owners and super-admins bypass), and the super-admin edits platform settings at `/admin/settings`.

### The profile hub

`/profile` is one tabbed page over every self-service account screen: profile fields, avatar, password, two-factor, PIN, sessions, appearance. Each tab owns its own form and endpoint; the page only orchestrates. Changing the email address asks for the current password, resets verification (so the new address must be confirmed), and revokes the user's other sessions.

Account deletion and a data export are wired as seams here, ready for the GDPR phase: a host (or a core module) contributes to the export by registering an `ExportsUserDataProvider`, and the delete action already refuses to remove a user who still owns a company.

### Editing email templates

In the operator console, "Email templates" lists the four core emails. The super-admin edits the subject and HTML body per locale, previews the result (rendered server-side and shown in a sandboxed iframe), and can send a throttled test to themselves. Saving validates that every `{{variable}}` used is declared for that template (an undeclared variable is a 422), sanitizes the HTML, and stores the override.

The core's own emails (verification, password reset, welcome, company invitation) render through these templates automatically. If a template is deactivated, each sender falls back to the static wording from the lang files, so mail never breaks.

## API reference

### `Settings` facade (host seam)

| Method | Purpose |
|--------|---------|
| `get(string $key, int|string|null $scopeId = null, mixed $default = null)` | Read a setting, cast and fail-closed. |
| `set(string $key, mixed $value, int|string|null $scopeId = null)` | Validate, cast and store a setting; busts the cache. |
| `allForScope(string $scope, int|string|null $scopeId = null)` | Every registered key in a scope with its current value. |
| `publicSettings()` | The app-scope keys flagged `public`, for sharing to the frontend. |
| `schemaForScope(string $scope)` | The form-editable keys of a scope, shaped for the UI. |
| `definition(string $key)` / `isRegistered(string $key)` / `scopeOf(string $key)` | Registry introspection. |

### Profile routes the host gets

Behind `web, auth` (the `/profile` prefix is in the email-verification except-list, so a user can reach it to re-verify after an email change):

```
GET    /profile                  profile.index            the tabbed hub
POST   /profile/avatar           profile.avatar.update
DELETE /profile/avatar           profile.avatar.destroy
PUT    /profile/ui-settings      profile.ui-settings.update
DELETE /profile/account          profile.account.destroy
GET    /settings                 settings.account
PUT    /settings/account         settings.account.update
```

Company settings (behind the active-tenant stack, teams mode):

```
GET    /settings/company         settings.company
PUT    /settings/company         settings.company.update
```

App settings and the email-template editor (behind the admin gate plus the OTP step-up):

```
GET    /admin/settings                       admin.settings.index
PUT    /admin/settings                       admin.settings.update
GET    /admin/email-templates                admin.email-templates.index
GET    /admin/email-templates/{code}/edit    admin.email-templates.edit
PUT    /admin/email-templates/{code}         admin.email-templates.update
POST   /admin/email-templates/{code}/preview admin.email-templates.preview
POST   /admin/email-templates/{code}/test    admin.email-templates.test   (throttled)
```

The host does not define these routes; it links to them.

### `UserDataExportRegistry` (GDPR seam)

A singleton (like the menu builder). A provider implements `ExportsUserDataProvider` and is registered on it; the core ships `CoreUserProfileExporter`. The real export and erasure flows arrive in the GDPR phase; this is the seam they stand on.

## Security notes

- **Settings are fail-closed.** Only keys declared in the registry can be read or written, so a stray or attacker-supplied key never reaches the table, and every value is cast and validated against its declared rule before it is stored.
- **Scope is resolved server-side.** A user setting is always the caller's own (the scope id comes from auth, never the request), so there is no cross-user write. A company setting is always the active company, resolved from the session and gated by RBAC, never an id from the request.
- **UI preferences are allowlisted.** The `users.ui_settings` column only accepts declared keys, each cast to its type; the donor wrote any key/value into that column, which this closes.
- **Email changes re-authenticate.** Changing an email requires the current password, resets verification and revokes other sessions.
- **The email renderer cannot execute code.** A stored template is rendered by a single-pass `{{token}}` substitution (`preg_replace_callback`), never compiled as Blade or evaluated as PHP, so a template authored in the database is structurally incapable of reaching an expression engine (no SSTI, no RCE), no matter what an operator types. This is the primary boundary.
- **Defense in depth on the editor.** Every `{{variable}}` a template uses is validated against an allowlist before save (an undeclared variable is rejected with a 422); the HTML body is run through HTMLPurifier with an email-friendly allowlist on write and on preview (scripts, event handlers and `javascript:` URLs are dropped); and the preview renders inside a sandboxed iframe with scripts off. The threat model treats the super-admin as trusted; these layers cover a compromised operator account or a bad paste, not the renderer's job.
- **The email editor is super-admin only.** It is behind the admin zone, the OTP step-up and an explicit policy.
- **Notification email is opt-in.** A notification only sends mail when it opts in and the `mail` channel is enabled, so turning the module on never starts surprise mailings.

## Testing

Settings live in `tests/Feature/Settings/` and `tests/Unit/Settings/`, profile in `tests/Feature/Profile/` and `tests/Unit/Profile/`, email in `tests/Feature/Email/` and `tests/Unit/Email/`. Notable coverage:

- The renderer's safety guard: a Pest test fails if rendering ever starts executing code, and the whitespace-tolerant single-pass behaviour is pinned.
- The HTML sanitizer drops scripts, event handlers and `javascript:` URLs while keeping email-friendly markup.
- The settings repository: fail-closed reads, validated and cast writes, per-scope caching, and the three-scope authorization (user is self, company is RBAC-gated and active-company-scoped, app is super-admin).
- The profile flows: email change re-auth and re-verification, session revoke, the ui_settings allowlist, avatar, and the owner-guarded account deletion.
- The email console: strict-variable validation (422 on an undeclared variable), the super-admin policy, the sandboxed preview, and the throttled test send.

Run them with Pest:

```bash
composer test
```
