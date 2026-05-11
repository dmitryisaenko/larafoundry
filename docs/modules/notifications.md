# Notifications Module

Dual notification system for admin broadcasts and automated system notifications in a multi-tenant SaaS.

## Features Overview

| Feature | Description |
|---------|-------------|
| Admin notifications | Manual creation with multilingual content, recipient segmentation, scheduling |
| System notifications | Automated via queued jobs, translation keys, 30-day auto-expiry |
| Read tracking | Per-user read_at timestamp via pivot table |
| Statistics | Total recipients, read count, read percentage per notification |
| Segmentation | 8 filter criteria for targeting specific user groups |
| Multilingual | JSON translations (admin) + Laravel translation keys (system) |
| Scheduling | Visibility window: visible_from / visible_until |
| Frontend | Unified panel, expandable items, auto-mark-as-read, polling |

## Database Schema

### notifications

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| code | string (indexed) | Unique notification identifier |
| notification_type | enum: admin, system | Notification source |
| status | enum: draft, sent | Workflow state |
| title_key | string (nullable) | Translation key for system notifications |
| body_key | string (nullable) | Translation key for system notification body |
| params | json (nullable) | Parameters for translation placeholders |
| title_translations | json (nullable) | Multilingual titles for admin notifications |
| body_translations | json (nullable) | Multilingual bodies for admin notifications |
| recipient_filters | json (nullable) | User segmentation criteria |
| data | json (nullable) | Arbitrary metadata |
| visible_from | timestamp (nullable) | Visibility start |
| visible_until | timestamp (nullable) | Visibility end |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### notification_user (pivot)

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| notification_id | foreign key | References notifications.id (cascade delete) |
| user_id | foreign key | References users.id (cascade delete) |
| read_at | timestamp (nullable, indexed) | When user read the notification |
| created_at | timestamp | Attachment time |
| updated_at | timestamp | Last update time |

Unique constraint on (notification_id, user_id).

## Notification Types

### Admin Notifications

Created manually via admin panel. Full CRUD with draft/sent workflow.

**Content**: Multilingual titles and bodies stored as JSON. English required, other languages optional. Auto-translate button fills empty fields.

**Targeting**: Recipient filters stored as JSON:
- Country
- Sex (m/f)
- Age range (16-100)
- Registration date (all, today, this_month, this_year)
- Recent activity (all, more, less_or_equal)
- Email verification (all, verified, unverified)
- Phone verification (all, verified, unverified)

**Scheduling**: visible_from and visible_until timestamps. Three visibility states: active, scheduled, expired.

**Workflow**: Create (draft) → Edit → Send → Resend or Delete. Status changes from 'draft' to 'sent' on send. One-way transition.

### System Notifications

Created programmatically by queued jobs when events occur.

**Content**: Laravel translation keys (`title_key`, `body_key`) with dynamic parameters via `params` JSON.

**Triggers**:
- Company lifecycle: created, deleted
- Invitations: sent, accepted, rejected (both sides)
- Employee management: removal request, removed
- Payments: success, failure
- Security: admin login attempts

**Expiry**: Auto-expire after configurable period (default: 30 days via `system_notification_lifetime_days`).

**Delivery**: In-app notification + optional email via Laravel's notification system.

## Localization

```php
// System notification - uses translation keys
public function getLocalizedTitle(string $locale = 'en'): string
{
    if ($this->isSystem()) {
        return __($this->title_key, $this->params ?? [], $locale);
    }

    // Admin notification - JSON lookup with fallback
    return $this->title_translations[$locale]
        ?? $this->title_translations['en']
        ?? '';
}
```

Fallback chain: requested locale → English → empty string.

## Admin Panel

### Routes

```
GET    /admin/notifications               → List all admin notifications
GET    /admin/notifications/create         → Creation form
POST   /admin/notifications                → Store draft
GET    /admin/notifications/{id}/edit      → Edit form
PUT    /admin/notifications/{id}           → Update notification
DELETE /admin/notifications/{id}           → Delete notification
POST   /admin/notifications/{id}/send      → Send to matching users
```

### Creation Form Sections

1. **Main info**: Type (info/warning), visible_from, visible_until
2. **Translations**: Language accordion, auto-translate button, required/filled badges
3. **Recipients**: Filter form with live user count (debounced 500ms)

### Statistics Display

Per-notification in admin table:
- Total recipients count
- Read count with percentage
- Progress bar visualization
- Visibility status badge (active/scheduled/expired)

## User Panel

### Routes

```
GET  /notifications              → Paginated notification list
GET  /notifications/unread       → Unread status and count
GET  /notifications/unread-recent → Last N unread for dropdown
GET  /notifications/recent       → Last N all for widget
POST /notifications/{id}/read    → Mark single as read
POST /notifications/mark-all-read → Bulk mark all as read
```

### UX Features

- Expandable notification items
- Auto-mark-as-read on expand (no extra click)
- 30-second polling for real-time unread count
- Global store sync for notification bell
- Type badges: info (blue), warning (yellow), system (gray)
- Mark all as read (single bulk DB query)

## Configuration

```php
// config/own.php
'notification_types' => [
    'info' => 'Info',
    'warning' => 'Warning',
],
'system_notification_lifetime_days' => 30,
'notifications_per_page' => 25,
```

## Key Patterns

- **Dual architecture**: One table, two creation flows, unified consumption
- **JSON translations**: Write-once admin content, no separate translations table
- **Reusable traits**: `HasUserFilterRules` for validation, `NotificationDataHandler` for data preparation
- **Filter injection**: `AdminUsersFilter` via DI, same auto-discovery pattern
- **Transaction safety**: All critical operations (send, delete) wrapped in DB::transaction
- **Pivot tracking**: read_at timestamp instead of boolean for analytics
- **Queued delivery**: System notifications via ShouldQueue jobs

## File Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Admin/NotificationController.php    # Admin CRUD + send
│   │   └── NotificationController.php          # User read/list
│   ├── Filters/AdminUsersFilter.php            # Shared user filtering
│   ├── Requests/Admin/AdminNotificationStoreRequest.php
│   └── Resources/
│       ├── Admin/AdminNotificationResource.php # Admin stats view
│       └── UserNotificationResource.php        # User panel view
├── Models/Notification.php                     # Dual-type model
├── Traits/
│   ├── HasUserFilterRules.php                  # Shared filter validation
│   └── NotificationDataHandler.php             # Data preparation
└── Jobs/                                       # System notification jobs

resources/js/
├── pages/
│   ├── admin/notifications/
│   │   ├── IndexNotifications.vue              # Admin list
│   │   ├── CreateNotification.vue              # Creation form
│   │   └── EditNotification.vue                # Edit + send
│   └── notifications/Index.vue                 # User panel
└── components/
    ├── NotificationItem.vue                    # Expandable item
    └── admin/notifications/NotificationTable.vue # Admin table

database/
├── migrations/
│   ├── create_notifications_table.php
│   └── create_notification_user_table.php
└── factories/NotificationFactory.php
```

## Testing

- Admin CRUD operations (create, edit, delete)
- Recipient segmentation accuracy
- Read tracking (single + bulk)
- Authorization (admin-only access)
- System notification jobs (creation + email delivery)
- Visibility status computation
