<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\AuthTestCase;
use Dmitryisaenko\LaraFoundry\Tests\Billing;
use Dmitryisaenko\LaraFoundry\Tests\TestCase;

// The auth (phase 1.1) suite needs DB migrations + Fortify/Socialite wiring,
// so it binds to the heavier AuthTestCase. Declared first and narrowly so the
// broad binding below does not also claim these folders (Pest forbids two test
// cases on the same path).
uses(AuthTestCase::class)->in('Feature/Auth', 'Unit/Auth', 'Feature/Tenancy', 'Unit/Tenancy', 'Feature/Authorization', 'Unit/Authorization', 'Feature/ActivityLog', 'Unit/ActivityLog', 'Feature/Localization', 'Feature/Admin', 'Feature/Navigation', 'Feature/Media', 'Feature/Billing', 'Feature/Credits', 'Feature/Notifications', 'Unit/Notifications', 'Feature/Tickets', 'Unit/Tickets', 'Feature/Profile', 'Feature/Settings', 'Feature/Email', 'Feature/Legal', 'Feature/Seo', 'Feature/Onboarding');

// Everything else keeps the lean, DB-less base case.
uses(TestCase::class)->in(
    'Feature/Middleware',
    'Feature/Rules',
    'Feature/ServiceProviderTest.php',
    'Unit/FilterTest.php',
    'Unit/HasPaginationTest.php',
    'Unit/Navigation',
    'Unit/Dashboard',
    'Unit/Media',
    'Unit/Billing',
    'Unit/Profile',
    'Unit/Settings',
    'Unit/Email',
    'Unit/Seo',
    'Unit/Onboarding',
);

/*
| Billing module suites (v0.45.0; until then the separate add-on's own suite). Each
| folder boots its own harness - a gateway, Cashier's provider, a billable fixture or
| the affiliate program - configured before boot, so each binds its own test case.
*/

// A gateway seeded in config before boot.
uses(Billing\WiringTestCase::class)->in('Billing/Feature/Wiring');

// The Stripe driver, Cashier's provider and a Billable fixture.
uses(Billing\StripeTestCase::class)->in('Billing/Feature/Stripe');

// The Stripe driver WITHOUT a webhook secret: the mirror must fail closed.
uses(Billing\StripeNoWebhookSecretTestCase::class)->in('Billing/Feature/StripeSecurity');

// The Paddle driver, Cashier-Paddle's provider and a Paddle Billable fixture.
uses(Billing\PaddleTestCase::class)->in('Billing/Feature/Paddle');

// The Paddle driver WITHOUT a webhook secret: the mirror must fail closed.
uses(Billing\PaddleNoWebhookSecretTestCase::class)->in('Billing/Feature/PaddleSecurity');

// Both gateways at once, tenants routed between them by region (D-B4-1).
uses(Billing\MultiGatewayTestCase::class)->in('Billing/Feature/Coexistence');

// The promo registry + free-month offer with NO gateway.
uses(Billing\PromoTestCase::class)->in('Billing/Feature/Promo');

// Billing on with the example plans and NO gateway: plan-feature entitlements.
uses(Billing\EntitlementTestCase::class)->in('Billing/Feature/Entitlements');

// Billing on with the BillableCompany tenant: the payment ledger and enforcement.
uses(Billing\PaymentsTestCase::class)->in('Billing/Feature/Payments', 'Billing/Feature/Enforcement');

// The owner billing screens: a checkout gateway + free month/promo on.
uses(Billing\ServicePaymentTestCase::class)->in('Billing/Feature/Billing');

// The super-admin payments + promo console.
uses(Billing\AdminConsoleTestCase::class)->in('Billing/Feature/Admin');

// The affiliate program: attribution, commission engine, partner dashboard.
uses(Billing\AffiliateTestCase::class)->in('Billing/Feature/Affiliate');

// The affiliate + payout super-admin console.
uses(Billing\AffiliateConsoleTestCase::class)->in('Billing/Feature/AffiliateConsole');

// Personal tenancy: the user is the payer, no companies anywhere.
uses(Billing\PersonalTestCase::class)->in('Billing/Feature/Personal');

// The personal-mode money path on the real Stripe harness.
uses(Billing\PersonalStripeTestCase::class)->in('Billing/Feature/PersonalStripe');

// Billing on, no gateway, no database: the config-backed plans and region routing.
uses(Billing\EnabledTestCase::class)->in(
    'Billing/Feature/PlanRepositoryTest.php',
    'Billing/Feature/RegionContextTest.php',
    'Billing/Feature/BillingSeamRebindingTest.php',
);

uses(Billing\TestCase::class)->in(
    'Billing/Feature/ServiceProviderSmokeTest.php',
    'Billing/Unit',
);
