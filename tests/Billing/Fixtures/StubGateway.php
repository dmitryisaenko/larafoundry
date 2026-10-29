<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Http\Request;

/**
 * A throwaway gateway used only to prove the add-on's extend() wiring registers a
 * configured driver against the core manager. It is a test fixture (export-ignored,
 * never shipped), not a payment implementation: real drivers are B3/B4.
 */
final class StubGateway implements PaymentGatewayInterface
{
    public function name(): string
    {
        return 'stub';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function subscribe(Tenant $tenant, string $planId, string $period, array $options = []): array
    {
        return ['ok' => true];
    }

    public function cancel(Tenant $tenant, bool $atPeriodEnd = true): void
    {
        //
    }

    public function refund(string $chargeReference, ?int $amount = null): void
    {
        //
    }

    public function subscriptionStatus(Tenant $tenant): string
    {
        return 'active';
    }

    public function verifyWebhook(Request $request): array
    {
        return [];
    }
}
