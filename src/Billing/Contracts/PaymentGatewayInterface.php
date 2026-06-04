<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Contracts;

use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Http\Request;

/**
 * The payment-gateway seam (phase 3.1, roadmap §91).
 *
 * Describes ONLY the mechanics of taking money — charge, subscribe, cancel,
 * refund, status, webhook verification. It deliberately says nothing about tax
 * or invoicing: those are a different responsibility that differs by gateway
 * type (with a PSP like Stripe the client is the merchant of record and owns
 * VAT/receipts; with a MoR like Paddle the provider owns them). Folding tax into
 * a gateway method would force one wrong model onto both. See memory
 * `monetization-model`.
 *
 * The FREE core ships exactly one implementation — {@see
 * \Dmitryisaenko\LaraFoundry\Billing\Support\NullPaymentGateway} — which refuses
 * every operation ("billing is not connected"). The paid `larafoundry-billing`
 * add-on registers real drivers (Cashier/Stripe, Cashier/Paddle) against this
 * contract; a host in a country those don't reach implements it for its local
 * PSP. The driver is resolved by {@see
 * \Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager} from
 * `config('larafoundry.billing.gateway.default')`, the Mail/Queue manager
 * pattern — so swapping providers never touches call sites.
 */
interface PaymentGatewayInterface
{
    /**
     * The driver's identifier (e.g. 'stripe', 'paddle', 'null'). Used in logs
     * and to tell which driver the manager resolved.
     */
    public function name(): string;

    /**
     * Whether this gateway can actually take money.
     *
     * The null driver returns false; real drivers return true once configured.
     * Call sites use this to branch ("billing not connected → free mode") instead
     * of catching exceptions from {@see subscribe()}.
     */
    public function isConfigured(): bool;

    /**
     * Start (or change to) a subscription for the tenant on the given plan and
     * period, returning a gateway-defined result payload (checkout url, session
     * id, subscription reference — shape is the driver's concern).
     *
     * @param  array<string, mixed>  $options  driver-specific extras (promo code, trial override, …)
     * @return array<string, mixed>
     */
    public function subscribe(Tenant $tenant, string $planId, string $period, array $options = []): array;

    /**
     * Cancel the tenant's active subscription. `$atPeriodEnd` true lets it run to
     * the paid-through date (the common, non-punitive default); false cancels
     * immediately.
     */
    public function cancel(Tenant $tenant, bool $atPeriodEnd = true): void;

    /**
     * Refund a charge by its gateway reference. Amount in minor units (cents);
     * null refunds the full charge.
     */
    public function refund(string $chargeReference, ?int $amount = null): void;

    /**
     * The tenant's current subscription status as the gateway sees it
     * (e.g. 'active', 'trialing', 'past_due', 'canceled', 'none'). The core's own
     * access gate reads the mirrored columns, not this — this is for reconciling
     * with the provider.
     */
    public function subscriptionStatus(Tenant $tenant): string;

    /**
     * Verify an incoming webhook is authentically from the provider BEFORE acting
     * on it, returning the verified payload. Implementations MUST check the
     * signature (the donor had no webhooks at all, so no precedent to copy — this
     * is non-negotiable for the add-on). The null driver always rejects.
     *
     * @return array<string, mixed> the verified, parsed event
     *
     * @throws WebhookVerificationException
     */
    public function verifyWebhook(Request $request): array;
}
