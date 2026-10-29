<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Http\Request;

/**
 * A test gateway that returns a hosted-checkout url and captures the subscribe options
 * (sub-phase B7a), so the controller tests can assert the redirect and the promo coupon
 * passed to the gateway WITHOUT touching Stripe.
 *
 * It reports its name as `stripe` so the controller's `couponIdFor($gateway->name())`
 * resolves a code's `stripe_coupon_id` — the real mapping path. No Stripe SDK is loaded:
 * the suite sets no webhook secret, so the add-on's Stripe mirror/recorder stay
 * unregistered.
 */
final class CheckoutGateway implements PaymentGatewayInterface
{
    /** @var array<string, mixed> */
    public static array $lastOptions = [];

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function subscribe(Tenant $tenant, string $planId, string $period, array $options = []): array
    {
        self::$lastOptions = $options;

        return [
            'checkout_url' => 'https://checkout.test/'.$planId.'/'.$period,
            'session_id' => 'cs_test_123',
        ];
    }

    public function cancel(Tenant $tenant, bool $atPeriodEnd = true): void {}

    public function refund(string $chargeReference, ?int $amount = null): void {}

    public function subscriptionStatus(Tenant $tenant): string
    {
        return 'active';
    }

    public function verifyWebhook(Request $request): array
    {
        return [];
    }
}
