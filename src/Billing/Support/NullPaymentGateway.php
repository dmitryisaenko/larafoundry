<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The FREE core's only gateway driver: it takes no money (phase 3.1).
 *
 * Billing is a paid add-on; the free core ships the seam, not the implementation.
 * Every money operation here refuses loudly so a misconfiguration (calling a
 * gateway with no driver bound) fails fast rather than silently "succeeding" —
 * the exact donor bug we are NOT carrying over (the donor hardcoded
 * `$paymentStatus = 'success'`). Read-only status reports stay quiet ('none').
 *
 * `isConfigured()` is false, so well-behaved call sites branch on that and never
 * reach the throwing methods; the throws are the backstop for the ones that don't.
 */
class NullPaymentGateway implements PaymentGatewayInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function subscribe(Tenant $tenant, string $planId, string $period, array $options = []): array
    {
        throw $this->notConnected();
    }

    public function cancel(Tenant $tenant, bool $atPeriodEnd = true): void
    {
        throw $this->notConnected();
    }

    public function refund(string $chargeReference, ?int $amount = null): void
    {
        throw $this->notConnected();
    }

    public function subscriptionStatus(Tenant $tenant): string
    {
        return 'none';
    }

    public function verifyWebhook(Request $request): array
    {
        // No provider, no shared secret — nothing can be authentic. Rejecting
        // (rather than returning an empty payload) keeps the contract's promise
        // that a returned payload is verified.
        throw new WebhookVerificationException('No payment gateway is configured to verify webhooks.');
    }

    protected function notConnected(): RuntimeException
    {
        return new RuntimeException(
            'No payment gateway is configured. Install and configure the '
            .'larafoundry-billing add-on, or bind a PaymentGatewayInterface driver.'
        );
    }
}
