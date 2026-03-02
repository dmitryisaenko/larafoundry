# Registration Module

> Multi-provider authentication with smart avatars, session tracking, and comprehensive logging.

## Overview

The Registration module handles user onboarding for your SaaS application. It supports multiple authentication methods, automatically generates user avatars, tracks sessions across devices, and logs every auth-related event from Day 1.

---

## Features

### 1. Multi-Provider Authentication

**Form Registration**
- Email + password registration with validation
- Configurable minimum password length
- Automatic email verification via signed links
- Welcome email notification (queued)

**OAuth2 (Social Login)**
- Google, Facebook, Twitter via Laravel Socialite
- Automatic email verification (OAuth = pre-verified)
- Provider token storage for API access
- Remember-me support across OAuth flow

**QR Code Login**
- Desktop generates QR code (5-minute expiry)
- Mobile scans and authenticates via signed URL
- Secure token-based verification

### 2. Smart Avatar System

Every user gets a professional avatar immediately upon registration.

**Pipeline:**

```
Email → Check Gravatar → Found? → Download + Resize to 128px
                           ↓ No
                    Generate Initials Avatar
                    (15 colors, bold typography)
                           ↓
                    Save as JPEG with UUID filename
                    to date-organized storage
```

**Packages used:**
- `creativeorange/gravatar` — Gravatar existence check and retrieval
- `laravolt/avatar` — Initials-based avatar generation
- `intervention/image` — Image resizing and format conversion

**Configuration:**
- 15 curated background colors
- 128x128px square format
- OpenSans-Bold and Rockwell fonts
- Deterministic colors (same name = same color)

### 3. Session & Device Tracking

Every login creates a `UserSession` record with:

| Field | Source | Example |
|-------|--------|---------|
| `ip_address` | Request | `192.168.1.1` |
| `user_device_type` | jenssegers/agent | `desktop` |
| `user_device_name` | jenssegers/agent | `Macintosh` |
| `user_os` | jenssegers/agent | `macOS` |
| `user_browser` | jenssegers/agent | `Chrome` |
| `login_method` | Controller | `google` / `form` |
| `user_agent` | Request | Full UA string |

### 4. Auth Event Logging

7 authentication events logged automatically via `spatie/laravel-activitylog`:

| Event | Description | Code |
|-------|-------------|------|
| `Registered` | New user created | 200 |
| `Login` | Successful login | 200 |
| `Failed` | Failed login attempt | 401 |
| `Logout` | User logged out | 200 |
| `ProfileUpdate` | Profile changed | 200 |
| `PasswordUpdate` | Password changed | 200 |
| `PasswordReset` | Password reset | 200 |

Each log entry includes: IP, device info, route, HTTP method, full URL, response code, user email, geo location.

### 5. Team Onboarding

- Company invitation system with token-based acceptance
- Auto-accept invitations during registration or OAuth login
- Email verification against invitation email (prevents spoofing)
- Automatic role assignment on team join
- `EmployeeInvitationAccepted` event for downstream processing

---

## Architecture

### Key Files

```
app/
├── Http/Controllers/Auth/
│   ├── RegisteredUserController.php    # Form registration
│   ├── OAuthLoginController.php        # OAuth2 flow
│   ├── EmailVerificationController.php # Email verification
│   ├── LoginWithQrCode.php             # QR code auth
│   └── ResetPasswordController.php     # Password reset
├── Services/
│   └── UserLogoService.php             # Avatar pipeline
├── Models/
│   ├── User.php                        # User model (MustVerifyEmail)
│   ├── UserSession.php                 # Session tracking
│   └── SignInRequest.php               # QR login tokens
├── Providers/
│   └── ActivityLogServiceProvider.php  # Event logging config
└── Notifications/
    ├── VerifyEmailNotification.php     # Custom verification email
    └── WelcomeEmailNotification.php    # Welcome email

resources/js/
├── layouts/app/
│   ├── AppGuestRegisterModalLayout.vue # Registration form
│   └── AppGuestLoginModalLayout.vue    # Login form + OAuth
└── pages/auth/
    ├── VerifyEmail.vue                 # Verification page
    └── ResetPassword.vue               # Password reset
```

### Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `laravel/socialite` | ^5.23 | OAuth2 authentication |
| `creativeorange/gravatar` | ~1.0 | Gravatar integration |
| `laravolt/avatar` | ^6.2 | Generated avatars |
| `intervention/image` | * | Image processing |
| `spatie/laravel-activitylog` | ^4.10 | Event logging |
| `jenssegers/agent` | ^2.6 | Device detection |
| `simplesoftwareio/simple-qrcode` | ^4.2 | QR code generation |

---

## Configuration

### OAuth Providers

Set in `.env`:

```env
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_CLIENT_REDIRECT=https://yourapp.com/oauth/google/callback

FACEBOOK_CLIENT_ID=your-facebook-client-id
FACEBOOK_CLIENT_SECRET=your-facebook-client-secret
FACEBOOK_CLIENT_REDIRECT=https://yourapp.com/oauth/facebook/callback

TWITTER_CLIENT_ID=your-twitter-client-id
TWITTER_CLIENT_SECRET=your-twitter-client-secret
TWITTER_CLIENT_REDIRECT=https://yourapp.com/oauth/twitter/callback
```

### Avatar Settings

`config/laravolt/avatar.php`:
- Image size: 128x128
- Shape: square
- Characters: 2 (initials)
- Font size: 48
- Fonts: OpenSans-Bold, Rockwell

---

## Routes

```php
// Guest routes
Route::post('register', [RegisteredUserController::class, 'store']);
Route::get('oauth/{provider}', [OAuthLoginController::class, 'redirect']);
Route::get('oauth/{provider}/callback', [OAuthLoginController::class, 'callback']);

// Authenticated routes
Route::get('verify-email', [EmailVerificationController::class, 'notice']);
Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify']);
Route::post('email/verification-notification', [EmailVerificationController::class, 'send']);
```
