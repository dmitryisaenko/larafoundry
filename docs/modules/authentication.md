# Authentication Module

> Production-grade, multi-method authentication system for Laravel SaaS applications.

## Overview

The Authentication module provides 6 login methods, 3 admin security layers, and comprehensive session management - all built with Laravel 12, Inertia.js v2, and Vue 3.

This module is extracted from **Kohana.io**, a production SaaS CRM/ERP, and is battle-tested with real users.

---

## Features

### 1. Email/Password Authentication

Standard form-based login with production-grade additions:

- **Rate Limiting** - 5 attempts per email+IP combination using Laravel's `RateLimiter`
- **Session Tracking** - Every login creates a `UserSession` record with device fingerprint
- **Remember Me** - Standard Laravel remember token support
- **Admin Detection** - Automatic 2FA redirect for admin accounts
- **Visitor Status Check** - Force-logout for blocked/unauthorized users

**Key Files:**
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- `app/Http/Requests/Auth/LoginRequest.php`

### 2. OAuth Authentication

Social login via Google, Facebook, and Twitter using Laravel Socialite v5.

- **Auto-linking** - Same email = same account, regardless of login method
- **Auto-verification** - OAuth emails are marked as verified instantly
- **Token Storage** - Provider tokens stored for future API integrations
- **Remember Me** - Persisted via session across OAuth redirect flow
- **Login Method Tracking** - Each session records how the user authenticated

**Key Files:**
- `app/Http/Controllers/Auth/OAuthLoginController.php`

**Configuration:**
```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
TWITTER_CLIENT_ID=
TWITTER_CLIENT_SECRET=
```

### 3. QR Code Authentication

Cross-device login system inspired by WhatsApp Web.

**Flow:**
1. Guest requests a QR code on the login page
2. Server generates a UUID token and creates a `SignInRequest` with 5-minute TTL
3. Token and request ID are encrypted and stored in the session
4. QR code (base64 SVG) contains an encrypted verification URL
5. Browser polls every 3 seconds for approval
6. Authenticated user scans the QR code from their device
7. Server verifies token, marks request as approved
8. Next poll detects approval and logs the user in

**Security:**
- UUID v4 tokens with 5-minute expiry
- Request IDs encrypted with `Crypt::encrypt()`
- Admin accounts cannot approve QR logins
- Both devices fingerprinted (IP + user agent)
- Session regenerated after login

**Key Files:**
- `app/Http/Controllers/Auth/LoginWithQrCode.php`
- `app/Models/SignInRequest.php`

**Dependencies:**
- `simplesoftwareio/simple-qrcode` - SVG QR code generation
- `ramsey/uuid` - UUID v4 token generation

### 4. PIN Code Screen Lock

Session-level PIN authentication for shared workstations.

**How It Works:**
- Users enable a 4-digit PIN from their profile
- PIN is BCrypt-hashed before storage
- `CheckPinLockMiddleware` checks inactivity on every request
- After configurable timeout (default: 30 minutes), the session locks
- Lock state is persisted to DB (cannot be bypassed via background requests)
- Each device/session is locked independently
- User is redirected to their last route after unlock

**Advantages over session timeout:**
- 4-digit unlock vs. full password + 2FA
- Session data preserved (no unsaved work lost)
- DB-persisted state prevents background request bypass
- Independent per-device control

**Key Files:**
- `app/Http/Controllers/PinController.php`
- `app/Http/Middleware/CheckPinLockMiddleware.php`

**Configuration:**
```php
// config/security.php
'pin_lock_timeout' => env('PIN_LOCK_TIMEOUT', 1800), // seconds
```

### 5. Two-Factor Authentication (2FA)

Google Authenticator (TOTP) for admin accounts.

- 6-digit code validation using `PragmaRX\Google2FA`
- `Require2FA` middleware enforces verification on all admin routes
- Session flag `2fa_verified` tracks verification state
- Failed 2FA attempts trigger admin notifications

**Key Files:**
- `app/Http/Controllers/Admin/TwoFactorController.php`
- `app/Http/Middleware/Require2FA.php`

**Configuration:**
```env
ADMIN_2FA_SECRET=your-google-authenticator-secret
```

### 6. Admin Security System

Three-layer protection for admin accounts:

**Layer 1 - IP Whitelisting:**
- Admin access restricted to comma-separated IP list
- Wrong IP = instant force-logout + notification

**Layer 2 - 2FA:**
- Google Authenticator required after login
- Wrong code = notification

**Layer 3 - Real-time Notifications:**
- Email + Telegram on every failed admin login attempt
- Includes: IP, geolocation, device fingerprint, failure step

**Key Files:**
- `app/Actions/GetVisitorStatusAction.php`
- `app/Helpers/AdminHelper.php`
- `app/Notifications/AdminLoginAttemptNotification.php`

**Configuration:**
```env
APP_ADMIN_EMAIL=admin@example.com
ADMIN_IPS=1.2.3.4,5.6.7.8
TELEGRAM_CHAT_ID=your-chat-id
TELEGRAM_BOT_TOKEN=your-bot-token
```

---

## Session Management

### UserSession Model

Every authenticated session is tracked with:

| Field | Description |
|-------|-------------|
| `session_id` | Laravel session ID |
| `user_id` | Authenticated user |
| `ip_address` | Client IP |
| `user_agent` | Full user agent string |
| `user_device_type` | desktop / tablet / phone |
| `user_device_name` | Device model |
| `user_os` | Operating system |
| `user_browser` | Browser name |
| `login_method` | native / google / facebook / twitter |
| `last_activity` | Timestamp (stored UTC, read in local TZ) |
| `pin_locked` | Boolean lock state |
| `last_route_name` | For redirect after PIN unlock |
| `active_company_id` | Multi-tenant support |

**Key Files:**
- `app/Models/UserSession.php`
- `app/Http/Controllers/Auth/RemoveSessionController.php`

---

## Visitor Status System

The `GetVisitorStatusAction` returns one of 6 states:

| Status | Condition |
|--------|-----------|
| `guest` | Not authenticated |
| `auth` | Normal authenticated user |
| `admin` | Admin with valid email + IP |
| `authBlocked` | User has `user_blocked_at` set |
| `authDeleted` | User has `user_deleted_at` set |
| `forcelogout` | Admin with invalid IP |

Used across middleware, controllers, and shared with the frontend via Inertia.

---

## Middleware Stack

| Middleware | Purpose | Applied To |
|-----------|---------|------------|
| `CheckPinLockMiddleware` | Auto-locks inactive sessions | All authenticated routes |
| `Require2FA` | Enforces 2FA verification | All admin routes |
| `AdminAccess` | Gates admin panel access | Admin route group |
| `prevent.nonadmin.routes` | Restricts non-admin routes | Email verification, QR approval |

---

## Routes

### Guest Routes
| Method | URI | Controller | Name |
|--------|-----|-----------|------|
| POST | `/register` | RegisteredUserController@store | register |
| POST | `/login` | AuthenticatedSessionController@store | login |
| GET | `/oauth/{provider}` | OAuthLoginController@redirect | oauth.redirect |
| GET | `/oauth/{provider}/callback` | OAuthLoginController@callback | oauth.callback |
| POST | `/password/forgot-password` | ResetPasswordController@sendEmail | password.email |
| GET | `/password/reset-password/{token}` | ResetPasswordController@resetForm | password.reset |
| POST | `/password/reset-password` | ResetPasswordController@resetHandler | password.update |

### QR Code Routes
| Method | URI | Controller | Middleware | Name |
|--------|-----|-----------|-----------|------|
| POST | `/qr/code-generate` | LoginWithQrCode@codeGenerate | guest | qr.codeGenerate |
| POST | `/qr/poll-login` | LoginWithQrCode@pollLogin | guest | qr.pollLogin |
| GET | `/qr/verify/{id}/{token}` | LoginWithQrCode@verifyLogin | auth | qr.verifyLogin |

### Authenticated Routes
| Method | URI | Controller | Name |
|--------|-----|-----------|------|
| GET | `/email/verify-email` | EmailVerificationController@notice | verification.notice |
| GET | `/email/verify-email/{id}/{hash}` | EmailVerificationController@verify | verification.verify |
| POST | `/email/verification-notification` | EmailVerificationController@send | verification.send |
| GET | `/pin` | - (Inertia) | pin.enter |
| POST | `/pin/check` | PinController@check | pin.check |
| POST | `/pin/enable` | PinController@enable | pin.enable |
| POST | `/pin/disable` | PinController@disable | pin.disable |
| POST | `/pin/lock` | PinController@lock | pin.lock |
| GET | `/logout` | AuthenticatedSessionController@destroy | logout |

### Admin Routes
| Method | URI | Controller | Middleware | Name |
|--------|-----|-----------|-----------|------|
| GET | `/admin/2fa` | TwoFactorController@show | AdminAccess, 2fa | admin.2fa.show |
| POST | `/admin/2fa` | TwoFactorController@verify | AdminAccess, 2fa | admin.2fa.verify |

---

## Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| laravel/socialite | v5 | OAuth authentication |
| pragmarx/google2fa | - | TOTP 2FA |
| simplesoftwareio/simple-qrcode | - | QR code SVG generation |
| jenssegers/agent | - | Device/browser detection |
| ramsey/uuid | - | UUID v4 token generation |

---

## Database Tables

### `user_sessions`
Tracks active sessions with device fingerprints and PIN lock state.

### `sign_in_requests`
Manages QR code authentication lifecycle (token, expiry, approval).

### `password_reset_tokens`
Standard Laravel password reset tokens (1-hour expiry).

---

*This module is part of [LaraFoundry](https://larafoundry.com) - an open-source SaaS framework for Laravel.*
