<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

/**
 * Boots the affiliate suite (Ф4.4): the billing-on payments harness with the affiliate
 * program turned ON.
 *
 * Reuses {@see PaymentsTestCase} (billing enabled, the BillableCompany tenant, the
 * users + company_payments tables) and flips `affiliate.enabled` so the program's
 * routes, listeners and console register. Individual tests override eligibility / trigger
 * / basis to exercise each configured variant.
 */
abstract class AffiliateTestCase extends PaymentsTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('larafoundry-billing.affiliate.enabled', true);

        // The affiliate suite exercises attribution and accrual, not payment emails; keep
        // the always-on NotifyOwnersOfPayment listener inert so firing CompanyPaymentProcessed
        // stays a pure accrual path.
        $app['config']->set('larafoundry-billing.notifications.enabled', false);

        // testbench ships no `web` middleware group (same reason ServicePaymentTestCase /
        // AdminConsoleTestCase narrow their groups); the capture route only needs cookies,
        // so the route-level throttle is the only middleware exercised here. The real
        // `web` group (EncryptCookies + session) is covered by the host integration test.
        $app['config']->set('larafoundry-billing.affiliate.attribution.capture_route.middleware', []);

        // The partner dashboard is gated by `auth` (the axis under test); `web` is dropped
        // for the same testbench reason.
        $app['config']->set('larafoundry-billing.affiliate.dashboard.middleware', ['auth']);
    }
}
