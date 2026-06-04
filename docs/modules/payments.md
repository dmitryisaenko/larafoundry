# Payments Module - Detailed Documentation

> **⚠️ Early design note (June 2025).** This page is an early planning draft from before the package was built. Billing shipped quite differently from this draft: the free core carries only a gateway-agnostic billing *seam* (a payment-gateway contract, subscription columns, and a real access gate), while the actual payment gateways (Stripe via Cashier, and more) live in a separate paid `larafoundry-billing` add-on. Treat this page as design intent, not current reference. For what actually ships, see the [README](../../readme.md) and the up-to-date module docs as they land. This file stays at its original URL so older links keep working.

## Overview

LaraFoundry's payment module provides a complete admin interface for tracking company payments, managing promo codes, and viewing revenue statistics across multiple currencies.

## Features

| Feature | Description |
|---------|-------------|
| Multi-Currency Stats | Revenue totals grouped by currency with configurable conversion |
| Promo Codes | Percentage and fixed discounts with 4-level validation chain |
| Personal Codes | Promo codes tied to specific users |
| Usage Limits | Global max uses + single-use-per-user constraint |
| Period Filtering | This month, last month, year, all time, custom range |
| Latest Payment | Auto-detection of current subscription per company |
| Audit Trail | Discount amount, reason, and promo code stored on each payment |
| Toggle Active | One-click promo code activation/deactivation |
| Responsive UI | Desktop table + mobile card layout |
| Gateway Debug | Raw JSON response stored and displayed in admin tooltip |

## Database Schema

### Tables

```
company_payments
├── id (PK)
├── company_id (FK → companies, cascade)
├── user_id (FK → users, cascade)
├── plan_id (string)
├── billing_period (enum: monthly, yearly)
├── amount (decimal 10,2)
├── currency (string 3, default 'USD')
├── discount_amount (decimal 10,2, default 0)
├── discount_reason (string, nullable)
├── promo_code_id (FK → promo_codes, nullable, nullOnDelete)
├── payment_status (enum: pending, success, failed)
├── payment_method (string, nullable)
├── payment_response (json, nullable)
├── paid_at (timestamp, nullable)
├── period_start (date)
├── period_end (date)
└── timestamps
Indexes: (company_id, payment_status), plan_id, user_id

promo_codes
├── id (PK)
├── user_id (FK → users, nullable, nullOnDelete)
├── code (string 50, unique)
├── description (text, nullable)
├── discount_type (enum: percentage, fixed)
├── discount_value (decimal 10,2)
├── max_uses (int, nullable)
├── used_count (int, default 0)
├── single_use_per_user (boolean, default true)
├── is_active (boolean, default true)
├── expires_at (timestamp, nullable)
└── timestamps
Indexes: code, (is_active, expires_at)
```

## API Endpoints

### Payments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /admin/payments | List payments with stats and filters |

### Promo Codes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /admin/promo-codes | List promo codes with filters |
| GET | /admin/promo-codes/create | Create form |
| POST | /admin/promo-codes | Store new promo code |
| GET | /admin/promo-codes/{id}/edit | Edit form |
| PUT | /admin/promo-codes/{id} | Update promo code |
| PATCH | /admin/promo-codes/{id}/toggle | Toggle active status |
| GET | /admin/promo-codes/search-users | User autocomplete |

## Promo Code Validation

The `canBeUsed(?int $userId)` method checks constraints in order (cheapest first):

1. **isValid()** - is_active = true AND (expires_at is null OR in future)
2. **Personal ownership** - if user_id is set, must match the requesting user
3. **Max uses** - if max_uses is set, used_count must be below it
4. **Single-use-per-user** - only counts successful payments (failed don't consume quota)

## Discount Calculation

- **Percentage**: `round(amount * discount_value / 100, 2)` - value capped at 100%
- **Fixed**: `min(discount_value, amount)` - never exceeds payment amount

## Promo Code Statuses

| Status | Condition |
|--------|-----------|
| Active | is_active AND not expired AND not exhausted |
| Inactive | is_active = false |
| Expired | expires_at < now |
| Exhausted | used_count >= max_uses |

## Filtering

### Payment Filters

| Filter | Type | Description |
|--------|------|-------------|
| period | enum | this_month, last_month, year, all, range |
| date_from / date_to | date | Manual range (when period = range) |
| payment_status | enum | success, failed |
| country | string | Filter by company country |
| plan_id | string | Filter by subscription plan |
| promo_code_id | int | Filter by promo code used |
| email | string | Search user email (LIKE) |
| company_name | string | Search company name (LIKE) |

Date filtering uses `COALESCE(paid_at, created_at)` to include pending/failed payments.

### Promo Code Filters

| Filter | Type | Description |
|--------|------|-------------|
| status | enum | active, inactive, expired, exhausted |
| type | enum | general (no user_id), personal (has user_id) |
| search | string | Search by code (LIKE) |

## Revenue Statistics

- Totals calculated as `SUM(amount - discount_amount)` grouped by currency
- Converted to admin display currencies (configurable)
- Respects all active filters
- Failed payment totals highlighted separately

## Latest Payment Detection

Per-company detection using `MAX(paid_at)` among successful payments. Marked on the payment row with `is_latest_for_company` flag. Shows subscription expiration status in the admin table.

## Validation Rules

### Store Promo Code

| Field | Rules |
|-------|-------|
| code | required, max:50, regex:/^[A-Z0-9_-]+$/, unique |
| discount_type | required, in:percentage,fixed |
| discount_value | required, numeric, min:0, max:100 (percentage) |
| max_uses | nullable, integer, min:1 |
| single_use_per_user | boolean |
| expires_at | nullable, date, after:now |
| user_id | nullable, exists:users,id |
| is_active | boolean |

### Update Promo Code

Same as store except: code (excluded), discount_type (excluded), expires_at (allows past dates).

## Events & Jobs

| Component | Type | Description |
|-----------|------|-------------|
| CompanyPaymentProcessed | Event | Fires after payment processing |
| NotifyOwnerAboutPaymentSuccess | Queued Job | Notifies company owner on success |
| NotifyOwnerAboutPaymentFailed | Queued Job | Notifies company owner on failure |

## Vue Components

| Component | Description |
|-----------|-------------|
| IndexPayments.vue | Main payments page with tab navigation |
| PaymentsTable.vue | Table/card with filters, totals, sorting |
| PromoCodesTab.vue | Promo codes list with filters and actions |
| CreatePromoCode.vue | Create form with code generator |
| EditPromoCode.vue | Edit form with read-only info block |

## File Structure

```
app/
├── Models/
│   ├── CompanyPayment.php
│   └── PromoCode.php
├── Http/
│   ├── Controllers/Admin/
│   │   ├── PaymentController.php
│   │   └── PromoCodeController.php
│   ├── Filters/
│   │   ├── AdminPaymentsFilter.php
│   │   └── AdminPromoCodesFilter.php
│   ├── Requests/Admin/
│   │   ├── AdminPaymentsFilterRequest.php
│   │   ├── AdminPromoCodesFilterRequest.php
│   │   ├── StorePromoCodeRequest.php
│   │   └── UpdatePromoCodeRequest.php
│   └── Resources/Admin/
│       └── AdminPaymentResource.php
├── Events/Company/
│   └── CompanyPaymentProcessed.php
└── Jobs/Company/
    ├── NotifyOwnerAboutPaymentSuccess.php
    └── NotifyOwnerAboutPaymentFailed.php

resources/js/Pages/Admin/
├── payments/
│   ├── IndexPayments.vue
│   └── components/
│       ├── PaymentsTable.vue
│       └── PromoCodesTab.vue
└── promo-codes/
    ├── IndexPromoCodes.vue
    ├── CreatePromoCode.vue
    └── EditPromoCode.vue

database/migrations/
├── 2025_11_07_222924_create_promo_codes_table.php
└── 2025_11_07_222937_create_company_payments_table.php

tests/
├── Feature/Admin/
│   ├── PaymentControllerTest.php
│   └── PromoCodeControllerTest.php
└── Unit/
    └── PromoCodeTest.php
```

## Testing

3 test files covering:
- Admin authorization
- Revenue statistics with multi-currency conversion
- Period, status, promo code filtering
- Full promo code CRUD lifecycle
- Validation (unique codes, regex, percentage cap, immutable fields)
- Personal vs general promo codes
- User search autocomplete
- Discount calculation (percentage and fixed)
- Usage tracking and single-use-per-user enforcement
- Status detection (active, inactive, expired, exhausted)
- Failed payments don't consume single-use quota
