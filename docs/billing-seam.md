# Billing Seam (FREE core)

The free core ships a billing *seam*, not a billing system: a gateway-agnostic
payment-gateway contract, subscription columns on the tenant, a fail-closed access
gate, and a set of swappable contracts (plans, entitlements, region). Out of the
box it takes no money and gates nothing - a free self-host is a fully usable
multi-tenant app with no paywall. Real payments plug into this seam from a
separate paid add-on.

The paid `larafoundry-billing` add-on (Stripe/Paddle via Cashier, entitlements,
promo codes, real charges and webhooks) lives in a **separate private repo** and
is documented there. It rebinds the contracts described here and registers real
gateway drivers. This page covers only what ships in the public core.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/payments.md](modules/payments.md); it described a
monolithic admin payments module (promo codes, revenue stats, Vue pages), which is
not what shipped in the core (see [What changed](#what-changed-from-the-early-draft)).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)
- [What changed from the early draft](#what-changed-from-the-early-draft)

## Install

The seam ships with the core package; there is nothing extra to require and no
add-on needed to have it present-but-inert. The package's migration adds five
billing columns to the `companies` table (`plan_id`, `billing_period`,
`trial_ends_at`, `subscription_ends_at`, `free_month_used_at`) and it is loaded
automatically. Each column is guarded with `hasColumn`, so the migration is
idempotent and safe to re-run.

The columns live in the FREE core (not the add-on) so the access gate can read
real subscription state with no add-on installed, and a free self-host can grant a
trial by hand. The add-on only *writes* them (its webhook sets
`subscription_ends_at`); it adds no column of its own to `companies`, keeping
cross-package ALTERs on this core table at zero.

The core's Company model already uses the `HasSubscription` trait and exposes
`hasAccess()`, so a host that stays on the free core does nothing further. A host
that wants real billing installs the paid add-on, which rebinds the contracts and
registers gateway drivers.

## Configuration

All billing settings live under the `billing` key of `config/larafoundry.php`:

```php
'billing' => [
    'enabled' => env('LARAFOUNDRY_BILLING_ENABLED', false),

    'gateway' => [
        'default' => env('LARAFOUNDRY_BILLING_GATEWAY', 'null'),
    ],

    'region' => [
        'default_currency' => env('LARAFOUNDRY_BILLING_CURRENCY', 'USD'),
    ],
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `enabled` | `false` | The master switch read by `LaraFoundryBilling::enabled()` and `Company::hasAccess()`. When `false` the access gate is always open (the free promise) and no gateway is expected to take money. When `true` the gate reads real subscription state. |
| `gateway.default` | `null` | The driver name the `PaymentGatewayManager` resolves by default. The free core only registers `null` (`NullPaymentGateway`). The paid add-on registers `stripe`/`paddle` and points this at one. An empty or whitespace value is treated as unset and falls back to `null`. |
| `region.default_currency` | `USD` | The single fallback currency `DefaultRegionContext::currencyFor()` returns. The core does no per-country currency mapping; the add-on/host overrides `RegionContext` to map country to currency. |

## Usage

### The one entry point

Call sites use the `LaraFoundryBilling` static facade rather than reaching for the
manager or resolvers directly, so they never depend on whether a real gateway is
installed. In the free core every accessor resolves to the default/null
implementation.

```php
use Dmitryisaenko\LaraFoundry\Billing\LaraFoundryBilling;

LaraFoundryBilling::enabled();          // bool - the master switch
LaraFoundryBilling::gateway();          // PaymentGatewayInterface (null driver in core)
LaraFoundryBilling::gateway($tenant);   // region-routed gateway for a tenant
LaraFoundryBilling::planFor($tenant);   // ?PlanContract (null in core - no plans)
LaraFoundryBilling::allows($tenant, 'reports.export'); // bool (true in core)
LaraFoundryBilling::region();           // RegionContext
```

### The access gate

`Company::hasAccess()` is the seam the access decision flows through. It has two
regimes, switched by `larafoundry.billing.enabled`:

```php
$company->hasAccess();
// billing disabled (default): always true - no paywall, the free core is fully usable.
// billing enabled: true only on a live trial OR an active subscription; else false (fail-closed).
```

The `HasSubscription` trait on Company exposes the underlying reads:

```php
$company->isOnTrial();              // bool - trial_ends_at in the future
$company->hasActiveSubscription();  // bool - subscription_ends_at in the future
$company->subscriptionState();      // SubscriptionState (memoised per instance)
$company->forgetSubscriptionState();// drop the memo after writing new state
```

**Honest scope note.** The gate is wired and answers correctly, but no core caller
enforces it yet. The intended Billing-RBAC loop (the RBAC policy checker or a
"subscription required" middleware consulting `hasAccess()`) has future call sites;
this phase wires none of them. Enabling billing makes the gate answer correctly,
but nothing in the core blocks on it until that wiring lands. There are zero real
payments, plans, checkout, or customer portal in the free core - that is the paid
add-on's job.

### Talking to a gateway (contract shape)

In the free core the resolved gateway is always `NullPaymentGateway`, whose
`isConfigured()` is `false`. Well-behaved call sites branch on that and never
reach the money methods, which throw loudly if called anyway.

```php
$gateway = LaraFoundryBilling::gateway($tenant);

if (! $gateway->isConfigured()) {
    // free mode - billing not connected
}
// The add-on's driver returns true here and implements subscribe/cancel/refund.
```

## API reference

### `LaraFoundryBilling` (entry point)

| Method | Returns | Purpose |
|--------|---------|---------|
| `enabled()` | `bool` | The `billing.enabled` master switch. |
| `gateway(?Tenant $tenant = null)` | `PaymentGatewayInterface` | Region-routes by tenant then the configured default; the null driver in the free core. |
| `region()` | `RegionContext` | Country/currency/gateway routing context. |
| `planFor(Tenant $tenant)` | `?PlanContract` | Reads `plan_id` off the tenant and looks it up through the bound repository; null in the free core (fail-closed on unknown id). |
| `allows(Tenant $tenant, string $feature)` | `bool` | Entitlement check; true for everything in the free core. |

### `PaymentGatewayInterface` (the gateway seam)

`Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface`. Describes
only the mechanics of taking money; it deliberately says nothing about tax or
invoicing (those differ by gateway type and are not a gateway-method concern).

| Method | Returns | Purpose |
|--------|---------|---------|
| `name()` | `string` | The driver identifier (`'stripe'`, `'paddle'`, `'null'`). |
| `isConfigured()` | `bool` | Whether the gateway can actually take money. `false` for the null driver. |
| `subscribe(Tenant $tenant, string $planId, string $period, array $options = [])` | `array` | Start/change a subscription; returns a driver-defined payload (checkout url, session id, ...). |
| `cancel(Tenant $tenant, bool $atPeriodEnd = true)` | `void` | Cancel; `$atPeriodEnd` runs to the paid-through date (the non-punitive default). |
| `refund(string $chargeReference, ?int $amount = null)` | `void` | Refund a charge by gateway reference; amount in minor units, null refunds full. |
| `subscriptionStatus(Tenant $tenant)` | `string` | The provider's view of status (for reconciliation, not the access decision). |
| `verifyWebhook(Request $request)` | `array` | Verify a webhook is authentic BEFORE acting, returning the verified payload. Implementations MUST check the signature. Throws `WebhookVerificationException`; the null driver always rejects. |

### `NullPaymentGateway` (the only core driver)

`Billing\Support\NullPaymentGateway`. `name()` is `'null'`, `isConfigured()` is
`false`. `subscribe()`, `cancel()`, `refund()` throw a `RuntimeException` ("No
payment gateway is configured..."). `subscriptionStatus()` returns `'none'`.
`verifyWebhook()` throws `WebhookVerificationException`. Read-only reports stay
quiet; every money operation refuses loudly so a misconfiguration fails fast
rather than silently "succeeding".

### `PaymentGatewayManager` (driver manager)

`Billing\Support\PaymentGatewayManager`. The Mail/Queue Manager pattern; a
singleton. The free core registers exactly one driver, `null`, as the default.

| Method | Returns | Purpose |
|--------|---------|---------|
| `extend(string $name, Closure $factory)` | `static` | Register/replace a named driver factory. The add-on/host calls this from its own service provider. Re-registering a name clears its memo. |
| `driver(?string $name = null)` | `PaymentGatewayInterface` | Resolve a driver (default when null). Memoised. Throws `InvalidArgumentException` when the name was never registered (no silent fallback that masks a misconfiguration). |
| `defaultDriver()` | `string` | `config('larafoundry.billing.gateway.default')`, falling back to `'null'` for null/empty. |
| `isConfigured()` | `bool` | Whether the resolved default gateway can take money. |
| `availableDrivers()` | `list<string>` | Names of every registered driver (diagnostics). |

### Swappable contracts (bound in the core, rebound by the add-on)

| Contract | Core default | Purpose |
|----------|--------------|---------|
| `Contracts\PlanContract` | (interface only) | A plan: `id()`, `name()`, `features()` (RBAC-slug vocabulary), `priceFor(string $period, string $currency): ?int` (minor units). |
| `Contracts\PlanRepositoryContract` | `Support\ArrayPlanRepository` (empty: `all()` is `[]`, `find()` is null) | The source of plan objects. Storage-agnostic seam. |
| `Contracts\EntitlementResolver` | `Support\NullEntitlementResolver` (`allows()` returns true) | Feature-gating by plan - the Billing-RBAC loop. |
| `Contracts\RegionContext` | `Support\DefaultRegionContext` | `countryFor()` (reads the tenant's `country` column, server-side), `currencyFor()` (configured fallback), `gatewayFor()` (null = use default). |

The service provider's `registerBilling()` binds `PaymentGatewayManager` as a
singleton and binds the three contracts above to their defaults (bound, not
singleton, so a host override takes cleanly). No payment SDK enters the free core's
dependencies.

### Support value objects

- `Support\SubscriptionState` - reads `trial_ends_at` / `subscription_ends_at` off
  a billable model, the single home for the "is this date in the future" logic.
  `isOnTrial()`, `hasActiveSubscription()`, `hasAccess()` (trial OR active). Malformed
  or non-date values normalise to null (fail-closed, never a crash).
- `Support\SubscriptionStatus` - a display-only classifier (phase 3.3) for the
  admin console: `on_trial`, `active`, `expiring`, `expired`, `never_activated`.
  Read-only; it manages nothing.

### `WebhookVerificationException`

`Billing\Exceptions\WebhookVerificationException` extends `RuntimeException`. A
distinct type so a webhook controller can map a failed signature check to a
400/403 without swallowing unrelated errors.

## Security notes

- **Money operations fail loud, not silent.** `NullPaymentGateway` throws on
  `subscribe`/`cancel`/`refund` and rejects every webhook. A call with no real
  driver bound fails fast rather than pretending success - the exact donor bug
  (a hardcoded `$paymentStatus = 'success'`) that is not carried over.
- **The billing columns are not mass-assignable.** `plan_id`, `billing_period`,
  `trial_ends_at`, `subscription_ends_at`, `free_month_used_at` are cast to dates
  but deliberately absent from `$fillable`. Subscription state is written
  server-side by the add-on (webhooks/actions) or a trusted admin, never from user
  input. A `Company::create([...'subscription_ends_at' => ...])` silently drops the
  value, so a user cannot self-grant access (a test pins this).
- **The gate is fail-closed when billing is on.** With `billing.enabled` true,
  `hasAccess()` grants only a live trial OR an active subscription; no billing date,
  or both expired, denies. A missing/garbage date decodes to null (denied), never
  an error.
- **The gate is open when billing is off.** This is intentional, not a gap: the free
  core has no paywall. `hasAccess()` short-circuits to true before reading any
  column, `NullEntitlementResolver::allows()` returns true, and the plan repository
  is empty.
- **Region is derived server-side.** `DefaultRegionContext` reads the tenant's own
  `country` column, never a client header or geo-IP. The core hardcodes no country.
- **Webhook verification is mandatory in the contract.** Any real driver MUST check
  the signature before acting and return only the verified payload; the null driver
  rejects unconditionally. (No webhooks ship in the free core - this is the contract
  the add-on's drivers must honour.)

## Testing

The billing suite lives in `tests/Feature/Billing/` and uses `RefreshDatabase`
with the test `User` fixture and the core `Company`:

- `HasAccessTest`: both gate regimes (open while disabled, fail-closed while
  enabled - active trial, active subscription, none, both expired), the
  `HasSubscription` accessors, and the mass-assignment guard on the billing columns.
- `BillingFacadeTest`: `LaraFoundryBilling` resolves the null gateway and no plan in
  the free core, allows every feature by default, and honours a rebound
  `PlanRepositoryContract` / `EntitlementResolver` (the add-on path).
- `BillingMigrationTest`: the five columns apply, and `down()`/`up()` round-trips
  cleanly (the `plan_id` index is dropped before its column) and is idempotent.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older [modules/payments.md](modules/payments.md), that page
described a monolithic admin payments module. Billing shipped as a seam split
across the free core and a separate paid add-on:

| Early draft | Shipped |
|-------------|---------|
| A single "payments module" with admin UI, promo codes, revenue stats | A gateway-agnostic *seam* in the free core; real payments/promo codes/portal are a separate paid `larafoundry-billing` add-on (private repo, documented there) |
| `company_payments` and `promo_codes` tables in the core | The free core adds only billing columns to `companies` (`plan_id`, `billing_period`, `trial_ends_at`, `subscription_ends_at`, `free_month_used_at`); the payments/promo tables belong to the add-on |
| Hardcoded gateway response, `$paymentStatus = 'success'` | `NullPaymentGateway` refuses every money operation loudly; real drivers are registered by the add-on via `PaymentGatewayManager::extend()` |
| Plans as a `config/own.php` array | `plan_id` is a plain string; plans sit behind `PlanRepositoryContract` (empty in the core), so storage is one binding |
| `/admin/payments`, `/admin/promo-codes` Vue pages | None in the free core; the access gate `Company::hasAccess()` is wired but not yet enforced by any core caller |
