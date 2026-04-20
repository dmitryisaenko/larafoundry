# Traits & Middlewares Module

The request lifecycle and reusable business logic for a multi-tenant Laravel SaaS.

## Middleware Stack

11 middlewares with strict execution order:

| # | Middleware | Purpose |
|---|-----------|---------|
| 1 | HandleInertiaRequests | Share props with Vue frontend (lazy-loaded closures) |
| 2 | SetActiveCompanyMiddleware | Resolve company context (owner priority) |
| 3 | UpdateLastSessionActivity | Track activity timestamp + device fingerprinting |
| 4 | AddLinkHeadersForPreloadedAssets | HTTP/2 server push |
| 5 | StoreIntendedUrl | Save URL for post-auth/PIN redirects |
| 6 | SetLocale | Detect and set language |
| 7 | EnsureEmailIsVerified | Gate unverified users with route exceptions |
| 8 | CheckPinLockMiddleware | Inactivity screen lock |
| 9 | CheckAccessMiddleware | User bans, owner bans, payment status |
| 10 | CheckSessionExists | Validate session in database |
| 11 | CheckForSessionValidity | CSRF regeneration + cookie cleanup |

### Order Dependencies

- SetActiveCompany → before → CheckAccess (access checks need company context)
- UpdateLastSessionActivity → before → CheckPinLock (PIN timeout uses last_activity)
- SetLocale → before → EnsureEmailIsVerified (verification page needs correct language)

### Configuration

```php
// bootstrap/app.php (Laravel 12)
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        HandleInertiaRequests::class,
        SetActiveCompanyMiddleware::class,
        // ... rest of stack
    ]);
})
```

## Middleware Details

### SetActiveCompanyMiddleware

Resolves which company context the current request runs in.

**Resolution priority:**
1. Valid session company (user switched manually) → keep it
2. First company where user is owner
3. First company where user is employee

**Edge cases:** deleted companies filtered, invalid session values cleared.

```php
$companies = $user->companies()->whereNull('deleted_at')->get();
$activeCompany = $companies->firstWhere('pivot.is_owner', true)
              ?? $companies->first();
```

### CheckPinLockMiddleware

Database-backed inactivity screen lock.

```php
$secondsSinceActivity = now()->diffInSeconds($session->last_activity);

if ($secondsSinceActivity >= config('security.pin_lock_timeout', 1800)) {
    $session->update(['pin_locked' => true]);
    // JSON → 423 Locked | Browser → redirect to pin.enter
}
```

- Default timeout: 30 minutes (configurable)
- State stored in `user_sessions.pin_locked` (not PHP session)
- API requests receive HTTP 423
- After unlock, redirects to `last_route_name`

### CheckAccessMiddleware

Three-level access control with route whitelists:

| Level | Condition | Allowed Routes | Redirect |
|-------|-----------|---------------|----------|
| 1. User ban | `user_blocked_at != null` | logout, notifications, tickets | user.blocked |
| 2. Owner ban | Company owner banned | (none) | company.payment.blocked |
| 3. Payment | Trial/subscription expired | payment pages, settings | company.payment.blocked |

### SetLocale

5-step language detection chain:

**Authenticated:** profile → session → browser Accept-Language → IP geolocation (2s timeout) → default

**Guest:** cookie (10yr) → session → browser → IP geo → default

Config-driven mappings:
```php
'browser_locale_map' => ['de' => 'de', 'uk' => 'ua'],
'country_locale_map' => ['DE' => 'de', 'UA' => 'ua'],
```

### UpdateLastSessionActivityMiddleware

Tracks per-request:
- `last_activity` timestamp
- `last_route_name` for PIN unlock redirect
- Device type (desktop/tablet/mobile), name, OS, browser via jenssegers/agent

Skips: PIN routes, non-HTML requests, redirects, excluded routes.

### Session Validation (CheckSessionExists + CheckForSessionValidity)

Two middlewares validate sessions against `user_sessions` table:

- **CheckSessionExists** - JSON requests get 401, browser gets redirect
- **CheckForSessionValidity** - Additionally forgets remember cookie + regenerates CSRF

Force logout = delete session row → next request rejected.

### Additional Middlewares

| Middleware | Purpose |
|-----------|---------|
| CheckCompanyAccess | Restricts `my_company.*` routes to owners (employees get specific exceptions) |
| EnsureEmailIsVerified | Gates with exceptions: verification pages, logout, support, profile |
| StoreIntendedUrl | Saves URL for `redirect()->intended()` after login/PIN, excludes PIN/API/search routes |
| HandleAppearance | Shares dark/light mode preference from cookie |
| AdminAccess | Admin-only route protection |
| PreventNonAdminRoutes | Prevents admins from accessing non-admin routes |
| Require2FA | Enforces 2FA for admin sessions |

## Custom Traits

### HasPagination

```php
trait HasPagination
{
    protected function getPaginationData($paginator): array
    {
        if (!$paginator instanceof LengthAwarePaginator
            && !$paginator instanceof Paginator) {
            return [];
        }

        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }
}
```

Type-safe. Returns empty array for non-paginator input. Used by every list controller.

### Filter Auto-Discovery

```php
abstract class Filter
{
    public function apply(Builder $builder)
    {
        $this->builder = $builder;
        foreach ($this->request->all() as $name => $value) {
            if (method_exists($this, $name)) {
                call_user_func_array([$this, $name], [$value]);
            }
        }
        return $this->builder;
    }
}
```

Request param name = method name. Features:
- Word-by-word search matching
- Whitelisted sort columns (SQL injection prevention)
- Alphabetical `starts_with()` filter
- Quick filter presets
- Validated via Form Requests

### NotificationDataHandler

Transforms validated form input into notification model data:
- Multilingual title/body (en, de)
- Recipient filters (country, sex, age range, registration date, activity, verification status)
- Visibility time windows

Used by both create and update controllers for consistent mapping.

### HasUserFilterRules

Companion to NotificationDataHandler. Provides reusable validation rules:
- Country from `config('app.available_countries')`
- Sex (m/f), age range (16-100)
- Registration period, activity level
- Email/phone verification status

### LogsActivity

Audit trail built on Spatie ActivityLog:
- Hooks into model created/updated/deleted events
- Records causer (who), subject (what), old/new values
- Only logs dirty attributes
- Add `use LogsActivity` to any model for automatic audit logging

## Interaction Diagram

```
HTTP Request
  │
  ├── [Middleware Stack]
  │   ├── Set company context (SetActiveCompanyMiddleware)
  │   ├── Track activity (UpdateLastSessionActivity)
  │   ├── Detect locale (SetLocale)
  │   ├── Check PIN lock (CheckPinLockMiddleware)
  │   ├── Check access (CheckAccessMiddleware)
  │   └── Validate session (CheckSessionExists)
  │
  v
Controller
  │   ├── HasPagination → format pagination data
  │   ├── Filter::apply() → auto-discover filters
  │   └── NotificationDataHandler → prepare notification data
  │
  v
Model Layer
      ├── BelongsToCompany → tenant isolation (global scope)
      ├── HasRolesAndPermissions → permission checks
      └── LogsActivity → audit trail
```

## Testing

Behavior-driven Pest tests:

```php
// PIN lock
it('locks after timeout', function () {
    $user = User::factory()->withPinCode()->create();
    UserSession::factory()->create([
        'user_id' => $user->id,
        'last_activity' => now()->subMinutes(31),
    ]);
    actingAs($user)->get('/dashboard')->assertRedirect(route('pin.enter'));
});

// Access control
it('blocks banned user from dashboard but allows tickets', function () {
    $user = User::factory()->blocked()->create();
    actingAs($user)->get('/dashboard')->assertRedirect(route('user.blocked'));
    actingAs($user)->get('/tickets')->assertOk();
});

// Filter auto-discovery
it('filters by country', function () {
    Contragent::factory()->create(['country' => 'DE']);
    Contragent::factory()->create(['country' => 'US']);
    actingAs($user)->get('/contragents/customers?country=DE')
        ->assertInertia(fn ($page) => $page->has('contragents', 1));
});

// Session validation
it('rejects invalid session', function () {
    actingAs($user)->getJson('/api/data')
        ->assertStatus(401);
});
```

## Design Principles

1. **Explicit middleware order** - each position justified by dependency on previous middleware
2. **Composable traits** - BelongsToCompany + HasRolesAndPermissions + LogsActivity = full tenant stack
3. **Layered security** - session → PIN → access control → permissions (multiple barriers)
4. **Config over code** - timeouts, locale maps, sort whitelists all configurable
5. **Behavior testing** - HTTP in, response out. No internal mocking.
