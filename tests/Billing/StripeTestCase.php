<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\CashierServiceProvider;

/**
 * Boots the add-on with the Stripe driver wired, against a real Billable model —
 * the closest the add-on suite gets to a configured host without leaving the
 * package. It:
 *
 *   - registers Cashier's provider so its `subscriptions` migration and webhook
 *     route exist (the add-on does not ship those — Cashier owns them);
 *   - declares `gateways.stripe` BEFORE boot so registerGatewayDrivers() registers
 *     the driver against the core manager (same trick as WiringTestCase's stub);
 *   - points `billable_model` at the {@see BillableCompany} fixture (a genuinely
 *     Billable Company subclass), with dummy Stripe secrets so isConfigured() is
 *     true and the webhook verifier has a signing secret — no real key, no network.
 *
 * The shipped config keeps `gateways` empty; turning Stripe on is the host's opt-in
 * (env + publish), which is exactly what this test case simulates.
 */
abstract class StripeTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            CashierServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The billing module is dormant unless billing is on (v0.45.0).
        $app['config']->set('larafoundry.billing.enabled', true);

        $app['config']->set('larafoundry-billing.gateways', [
            'stripe' => CashierStripeDriver::class,
        ]);

        $app['config']->set('larafoundry-billing.billable_model', BillableCompany::class);

        // Map the example plans to test Stripe price ids so the price ↔ plan
        // reverse lookup (used by the webhook mirror) has something to resolve. The
        // shipped config sources these from env (null in tests).
        $app['config']->set('larafoundry-billing.plans.definitions.pro.gateways.stripe', [
            'monthly' => 'price_pro_monthly',
            'yearly' => 'price_pro_yearly',
        ]);
        $app['config']->set('larafoundry-billing.plans.definitions.business.gateways.stripe', [
            'monthly' => 'price_business_monthly',
            'yearly' => 'price_business_yearly',
        ]);

        $app['config']->set('larafoundry-billing.stripe.secret', 'sk_test_dummy');
        $app['config']->set('larafoundry-billing.stripe.webhook.secret', 'whsec_test_dummy');

        // Cashier itself reads cashier.secret on boot; keep it present so its
        // provider does not warn, and so the driver's fallback path is exercised.
        $app['config']->set('cashier.secret', 'sk_test_dummy');

        // This suite proves the mirror and the payment recorder; owner notifications
        // are exercised in the payments suite, so keep them off here to decouple this
        // suite from a Notifiable host user model.
        $app['config']->set('larafoundry-billing.notifications.enabled', false);

        // Cashier::findBillable() (used by the webhook mirror) resolves the customer
        // by stripe_id against its configured customer model, which defaults to
        // App\Models\User. Point it at our Billable Company fixture so the mirror
        // finds the right record.
        Cashier::useCustomerModel(BillableCompany::class);
    }

    /**
     * Stand up the base `users` table the core only ALTERs, before the package and
     * Cashier migrations (loaded by their providers) run. The `0001_` filename sorts
     * it ahead of every `2026_*` migration, so companies.created_by_id has a target.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
