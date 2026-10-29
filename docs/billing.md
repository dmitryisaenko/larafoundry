# Billing module

Real payments on top of the [billing seam](billing-seam.md): Stripe and Paddle (merchant of record) gateways through Laravel Cashier, config-backed plans and per-country routing, promo codes and a no-card free month, plan entitlements, access enforcement, a payment ledger with owner notifications, a super-admin payments / promo / affiliate console, a revenue dashboard widget and an affiliate program.

Until `v0.45.0` this was the separate private `dmitryisaenko/larafoundry-billing` add-on. It now ships inside the core: `src/Billing/` (namespace `Dmitryisaenko\LaraFoundry\Billing\`), `config/larafoundry-billing.php`, `routes/billing/`, the billing migrations in `database/migrations/`, the pages in `resources/js/Pages/{Billing,Affiliate,Admin/Payments,Admin/PromoCodes,Admin/Affiliates,Admin/Payouts}`. Its provider, `Billing\BillingServiceProvider`, is registered by the core provider, so there is nothing extra to install or discover.

## One switch

Everything hangs off `larafoundry.billing.enabled` (env `LARAFOUNDRY_BILLING_ENABLED`, default `false`).

| | Billing off (default) | Billing on |
|---|---|---|
| Tables (`company_payments`, `promo_codes`, `promo_code_redemptions`, `affiliates`, `referrals`, `affiliate_commissions`, `paddle_*`) and billing columns | created by `migrate` | created by `migrate` |
| Routes: `billing.*`, `admin.payments.*`, `admin.promo-codes.*`, `admin.affiliates.*`, `admin.payouts.*`, `affiliate.*`, the promo validate endpoint | none | loaded (each also behind its own flag below) |
| Webhook routes: Cashier-Stripe's `/stripe/*`, the Paddle webhook `/paddle/webhook` | turned off | each only with its gateway in `gateways` |
| Admin menu items, revenue dashboard widget, partner menu item | none | added |
| Webhook mirrors, payment recorder, owner payment notifications, referral attribution | not attached | attached |
| `PlanRepositoryContract` / `RegionContext` / `EntitlementResolver` | the seam's empty defaults (no plans, default currency, everything allowed) | config-backed plans, per-country routing, plan-feature entitlements |
| `entitlement` / `billing.access` middleware aliases | registered, pass everything | registered, enforce |
| Gateway drivers in the manager, the promo rate limiter, the promo ability, `Company::payments`, the `larafoundry-billing:check-expiring` command | registered (inert) | registered |

The tables exist either way on purpose: a table that exists or not depending on config is a trap for any code that looks into it while billing is off. The contracts are chosen per call, so flipping the switch at runtime (a test does) is consistent.

Routes are not: a host that caches its routes (`php artisan route:cache`) must re-run it after flipping the switch or changing `gateways`, or the cached file keeps the old set, webhook routes included.

`config/larafoundry-billing.php` holds the rest of the settings; publish it with `php artisan vendor:publish --tag=larafoundry-billing-config`.

## Making the payer billable

The core `Company` stays gateway-agnostic. A host applies the `BillingCustomer` trait (Cashier identity, Stripe) to its own Company subclass and points the config at it:

```php
namespace App\Models;

use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company as BaseCompany;

class Company extends BaseCompany
{
    use BillingCustomer;
}
```

```php
// config/larafoundry.php
'tenancy' => ['company_model' => App\Models\Company::class],
// config/larafoundry-billing.php
'billable_model' => App\Models\Company::class,
```

The two Cashier packages each ship a `Billable` trait with colliding method names, so a host that also routes tenants to Paddle declares a SECOND subclass with `BillingCustomerPaddle` and points `paddle_billable_model` at it. Both share the `companies` row.

## Gateways

List the drivers to register under `gateways` and route tenants to them with `larafoundry.billing.gateway.default` and `region.gateways` (country => gateway).

```php
'gateways' => [
    'stripe' => \Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver::class,
    'paddle' => \Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver::class,
],
```

**Stripe.** Hosted Stripe Checkout, cancel, refund, status and signed webhooks. `MirrorStripeSubscriptionState` keeps the access columns (`subscription_ends_at`, `trial_ends_at`, `plan_id`) in sync from verified webhooks; `RecordStripePayment` writes the ledger. The Stripe price per plan and period lives at `plans.definitions.<id>.gateways.stripe.<period>`.

```dotenv
STRIPE_SECRET=sk_live_...
STRIPE_KEY=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...   # mandatory: without it the mirror is not attached at all
STRIPE_PRICE_PRO_MONTHLY=price_...
```

**Paddle.** The merchant-of-record driver for regions where VAT and invoicing should be the gateway's. Cashier-Paddle is pointed at the module's own `paddle_`-prefixed tables, so Stripe and Paddle run side by side without colliding on `subscriptions` / `customers`.

```dotenv
PADDLE_API_KEY=...
PADDLE_CLIENT_SIDE_TOKEN=...
PADDLE_WEBHOOK_SECRET=...         # mandatory for the mirror, same rule as Stripe
PADDLE_SANDBOX=true
PADDLE_PRICE_PRO_MONTHLY=pri_...
```

The module keys `larafoundry-billing.{stripe,paddle}.webhook.secret` are bridged into Cashier's own keys when those are empty, so the route's signature check and the mirror read the same secret. Cashier-Paddle's own route registration stays off: it prefixes its webhook with the `cashier.path` key it shares with Cashier-Stripe (which sets it to `stripe`), so it would take over `/stripe/webhook`. The module declares the Paddle webhook itself at `/paddle/webhook` (route name `larafoundry-billing.paddle.webhook`, Cashier-Paddle's own controller); point the Paddle dashboard there. Note that both Cashier packages read the plain `PADDLE_*` / `STRIPE_*` env names: a host that already uses them for its own payment code shares them with the module once billing is on.

## One gateway account, several sites

Several installations of one code (each with its own database) may share one
gateway account. A gateway does not sort its notifications by site, so every
site's notification address receives every payment, and user ids of different
databases run level. `Billing\Support\PaymentOrigin` keeps them apart:

```dotenv
LARAFOUNDRY_INSTALL=lorevid             # stamped on payments this site opens
LARAFOUNDRY_INSTALL_UNMARKED=comentor   # whose an UNSTAMPED payment is (optional)
```

`PaymentOrigin::stamp($data)` writes `install` into the gateway's free-form data
(Paddle `custom_data`, Stripe `metadata`), `PaymentOrigin::isOurs($data)` answers
for a notification. Unset - one site, nothing stamped, every payment ours. Read
with billing off too (credit purchases use it).

## Who may pay in a sandbox

`Billing\Contracts\SandboxTesters` - while a gateway runs in its sandbox, only the
people it lets through may open a checkout (a sandbox payment takes no real
money, so a customer must not reach one on a live site). The default,
`NoSandboxTesters`, lets nobody through; bind your own:

```php
$this->app->bind(SandboxTesters::class, fn () => new class implements SandboxTesters {
    public function allows(?Authenticatable $user): bool
    {
        return (bool) $user?->can_test_payments;
    }
});
```

## Plans, region, entitlements

Plans are config (`plans.definitions`): a display name, entitlement `features`, display `prices` per currency and period in minor units, and gateway price ids. `region.currencies` maps a country to a currency; the country is read server-side from the payer, never from the client.

Entitlements close the Billing/RBAC loop. RBAC answers "who in the company may"; the plan answers "which plan pays for it"; both share one slug vocabulary:

```php
Route::middleware(['can:production.view', 'entitlement:production.module'])
    ->get('/production', ProductionController::class);
```

A tenant whose plan lacks the feature gets a 403 (JSON) or a redirect to `entitlements.redirect_route` (browser). `billing.access` is the separate subscription-liveness axis (`enforcement.mode` soft = banner, hard = redirect to the blocked page); it never protects paid modules on its own.

## Promo codes and the free month

The registry owns validity, personal and single-use codes and redemption auditing; the gateway computes the charged amount. The free month is an app-side grant on `trial_ends_at` / `free_month_used_at`, so a "no credit card" trial works with any gateway or none. The public validate endpoint (`promo.validate_route`, off by default) is always rate-limited and answers one generic message on any failure, so it cannot enumerate codes.

## Console, dashboard, notifications

With billing and `admin.enabled` on, the operator console gains (behind the same `larafoundry.admin.otp` step-up as the rest of the console) Payments (read-only ledger with per-currency totals), Promo codes (CRUD) and, with the affiliate program on, Affiliates and Payouts. The revenue widget (`RevenueWidget`, built into the core widget registry) shows per-currency totals for this month / last month / this year / all time and the live active-subscription count; there is no currency conversion.

A recorded payment fires `CompanyPaymentProcessed`; with `notifications.enabled` the owners get a queued success / failure mail. `larafoundry-billing:check-expiring` mails expiry reminders on the configured day-marks; schedule it yourself (`->daily()`).

## Affiliate program

Off by default (`affiliate.enabled`). Three independent axes: eligibility (`auto` / `self_serve` / `admin`), commission trigger (`first_payment` / `recurring` / `lifetime`) and basis (`per_plan` / `percent` / `fixed`). Money is whole minor units in the payment's currency, never converted. Attribution is a throttled capture route (`/r/{code}`) plus an encrypted last-click cookie, locked on company creation (`teams`) or registration (`personal`).

## Personal tenancy mode

In `personal` mode there are no companies: the payer is the person. Everything that needs "who pays" goes through `Support\BillingSubject`. A host bills per user with two traits and one config line:

```php
use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer;    // Cashier identity (Stripe)
use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingSubscriber;  // hasAccess() over the mirrored columns

class User extends Authenticatable
{
    use BillingCustomer;
    use BillingSubscriber;
}
```

```dotenv
LARAFOUNDRY_TENANCY_MODE=personal
LARAFOUNDRY_BILLING_BILLABLE_MODEL="App\Models\User"
```

- `billable_model` must name the class the auth guard resolves, and Cashier's own customer model must point there too, or incoming webhooks find nobody.
- `BillingSubscriber` is mandatory once billing is on: without it the access gate fails closed.
- Pick the mode before taking money: the tenant columns (`company_payments.company_id` and friends) hold a company id in `teams` and a user id in `personal`.

## Moving from the larafoundry-billing add-on

1. Remove `dmitryisaenko/larafoundry-billing` from `composer.json` (and its `path` / `vcs` repository) and update the core to `v0.45.0`.
2. Replace the namespace: `Dmitryisaenko\LaraFoundryBilling\X` becomes `Dmitryisaenko\LaraFoundry\Billing\X`, except the former `LaraFoundryBilling\Billing\*` classes (`ConfigPlan`, `ConfigPlanRepository`, `ConfiguredRegionContext`, `PlanEntitlementResolver`), which are now in `...\Billing\Support\`.
3. In a published `config/larafoundry-billing.php`, drop the `license` block (there is no license check any more).
4. Translations moved from `larafoundry-billing::billing.*` to `larafoundry::billing.*`; a published `lang/vendor/larafoundry-billing/` override moves to `lang/vendor/larafoundry/`.
5. The `registerBillingWidgets` import in `app.js` is no longer needed (`RevenueWidget` is a built-in).
6. Behaviour changes with billing **off**: no billing route or menu item at all (the add-on loaded the affiliate capture / dashboard and the promo validate routes on their own flags), Cashier's `/stripe/*` and the `/paddle/webhook` route are off (so a gateway configured with billing off stops receiving webhooks: turn billing on before upgrading), the webhook listeners are not attached, and the plan / region / entitlement contracts answer with the seam's defaults. A host that relied on any of that with billing off turns billing on.

7. The console screens now also require the super-admin OTP step-up (`larafoundry.admin.otp`), like every other console screen; a published `admin.middleware` list without it keeps the old behaviour.
8. The Paddle webhook route is now named `larafoundry-billing.paddle.webhook` (Cashier-Paddle registered it as `cashier.webhook`, the same name as Stripe's).

Otherwise route names, config keys, the publish tags, the middleware aliases, the console command and the migration file names are unchanged, so an installed host does not re-run any migration.
