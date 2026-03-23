# Logging Module

## Overview

LaraFoundry's Logging module provides a production-grade, event-driven activity logging system. Every significant user action is automatically captured with full context: device fingerprint, geolocation, request metadata, and module-specific properties.

## Architecture

```
User Action -> Event Fired -> ActivityLogServiceProvider (listener)
    -> ActivityLogService::logActivity()
        -> CustomActivity record (DB)
        -> RetrieveActivityGeoData job (queue)
            -> GetGeoDataByIpAction (cached geo-lookup)
                -> CustomActivity updated with geo data
```

## Package Stack

| Package | Version | Purpose |
|---------|---------|---------|
| `spatie/laravel-activitylog` | ^4.10 | Core activity tracking (extended with custom model) |
| `jenssegers/agent` | ^2.6 | Device/browser/OS detection from User-Agent |
| `opcodesio/log-viewer` | ^3.21 | Web UI for Monolog file logs |
| `laravel/telescope` | ^5 | Application debugging (dev/testing only) |

## Key Components

### ActivityLogServiceProvider

Central event-to-log mapping. Registers listeners for 60+ events across all modules:

- **Auth:** Registered, Login, Logout, Failed, PasswordReset, ProfileUpdate, PasswordUpdate
- **Company:** CompanyCreate, CompanyDeleted, EmployeeInvitationSent, RoleCreated, RoleUpdated, etc.
- **Warehouse:** ProductCreated/Updated/Deleted, CategoryCreated/Updated/Deleted, AttributeCreated/Updated/Deleted (including failure events)
- **Contragent:** ContragentCreated/Updated/Deleted/Restored (including step-wise updates and failure events)
- **Improvements:** Created, Updated, Deleted, Voted, Unvoted, Commented, StatusChanged
- **Tickets:** TicketCreate, TicketAnswerCreate
- **User:** UserBlocked, UserUnblocked

Each event can optionally implement `getLogProperties()` for custom context data.

### ActivityLogService

Three logging methods:
1. `log()` - Generic activity logging with immediate geo-lookup
2. `logActivity()` - Event-based logging with async geo-lookup via queue
3. `logMethodExecution()` - Method execution tracking with before/after state

### CustomActivity Model

Extends `Spatie\Activitylog\Models\Activity` with additional fields:
- Device fingerprint: `user_ip`, `user_device_type`, `user_device_name`, `user_os`, `user_browser`, `user_agent`
- Request context: `route_name`, `request_method`, `full_url`, `response_code`
- Status: `is_successful`, `user_email`
- Geolocation: `geo_country`, `geo_city`, `geo_updated_at`

### GetGeoDataByIpAction

Invokable action for IP geolocation:
- Uses ip-api.com (free tier)
- 24-hour cache per IP
- Local IP detection (skips API for 127.0.0.1, 192.168.*, 10.*, 172.*)
- 5-second timeout, graceful fallback to "Unknown"

### RetrieveActivityGeoData Job

Queued job for async geo enrichment:
- 30-second timeout
- 3 retry attempts
- Graceful failure handling (sets "Unknown" on total failure)

### LogActivity Middleware

Optional middleware for automatic route access logging:
- Logs route name, parameters, status code
- Excludes noisy routes (sanctum, ignition, etc.)
- Currently available but disabled by default

## Admin Notifications

`AdminLoginAttemptNotification` sends alerts via Email + Telegram when admin login is attempted. Captures device data during HTTP request (before queue serialization) to preserve Agent context.

## File-Based Logging (Monolog)

Split channel configuration:
- **Stack driver:** Routes to `daily` + `critical`
- **Daily channel:** `storage/logs/laravel.log`, 14-day retention
- **Critical channel:** `storage/logs/critical.log`, error level only, 30-day retention
- **Slack channel:** Critical errors via webhook (configurable)

## Log Viewer

Web interface at `/admin/log-viewer`:
- Authentication required in production
- Multi-host support (local + remote)
- Stack trace filtering
- Dark theme default

## Telescope

Development/testing debugger:
- Enabled only in `local` and `testing` environments
- 15+ watchers: Query (slow > 100ms), Model, Event, Job, Exception, Mail, Gate, etc.
- Database-backed storage

## Admin UI (Inertia/Vue)

Two views:
1. **User logs** (`/admin/logs/{user}`) - Per-user activity with time range filter (1-72 hours)
2. **General logs** (`/admin/generalLogs`) - System-wide activity timeline

Features: responsive layout (tables + cards), tooltips with device info, status badges, pagination.

## Data Retention

| Storage | Retention |
|---------|-----------|
| Activity logs (DB) | 365 days |
| Daily file logs | 14 days |
| Critical file logs | 30 days |
| Telescope | Configurable (dev only) |

## Adding Logging to New Modules

1. Create your events (e.g., `InvoiceCreated::class`)
2. Optionally implement `getLogProperties()` on the event
3. Add the event to `$events` array in `ActivityLogServiceProvider`

That's it. No manual logging calls needed.
