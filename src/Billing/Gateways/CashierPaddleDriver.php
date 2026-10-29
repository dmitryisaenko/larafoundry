<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Gateways;

use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomerPaddle;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayNotConfiguredException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayOperationException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Paddle\Billable;

/**
 * The real Paddle gateway (sub-phase B4), implementing the core's
 * {@see PaymentGatewayInterface} on top of Laravel Cashier-Paddle.
 *
 * Sibling to {@see CashierStripeDriver}; the two coexist behind the core's
 * PaymentGatewayManager and a tenant is routed to exactly one by region (B2's
 * region.gateways map). The deltas from the Stripe driver are all rooted in two
 * facts about Paddle:
 *
 *  - Paddle is a Merchant-of-Record. It owns VAT, invoicing and (mostly) refunds —
 *    the tax/invoice concerns the contract deliberately omits. refund() is a
 *    deferred stub here (decision D-B4-3): programmatic refunds via the adjustments
 *    API are a later sub-phase; for the MVP a refund is issued through Paddle.
 *  - Paddle checkout is a redirect-LESS JS overlay (Paddle.js `Checkout.open`), not
 *    a hosted redirect url like Stripe Checkout. So subscribe() returns the overlay
 *    options + the client-side token (decision D-B4-2), not a `checkout_url`; the
 *    host's UI branches on `mode`. The contract allows this — "shape is the driver's
 *    concern."
 *
 * Like the Stripe driver it bridges the core's {@see Tenant} to a Cashier Billable
 * via {@see billableFor()}, but resolves its OWN `paddle_billable_model` (the host's
 * SECOND Company subclass using {@see BillingCustomerPaddle}, because one model
 * cannot carry both gateways' colliding Billable traits — decision D-B4-1).
 *
 * Registered against the core PaymentGatewayManager by the service provider from
 * `config('larafoundry-billing.gateways.paddle')`.
 */
final class CashierPaddleDriver implements PaymentGatewayInterface
{
    /**
     * Upper bound on h1 hashes parsed from a `Paddle-Signature` header. Paddle sends
     * one; the cap stops an attacker-supplied header from forcing unbounded work.
     */
    private const MAX_SIGNATURE_HASHES = 4;

    public function __construct(private readonly Config $config) {}

    public function name(): string
    {
        return 'paddle';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null
            && $this->classIsBillable($this->billableClass());
    }

    public function subscribe(Tenant $tenant, string $planId, string $period, array $options = []): array
    {
        $billable = $this->billableFor($tenant);
        $priceId = $this->priceId($planId, $period);

        // Paddle's subscribe() returns a Checkout object for the JS overlay, NOT a
        // SubscriptionBuilder + redirect like Stripe. The subscription is created
        // client-side via Paddle.js and confirmed back to us by webhook; the mirror
        // listener writes the access columns then.
        $checkout = $billable->subscribe($priceId, 'default')
            ->returnTo($options['return_url'] ?? $this->returnUrl() ?? '');

        // A catalogue promo code resolved by our registry (B5, D-B5-1 hybrid): the
        // RedeemPromoCodeAction maps a registry code to a Paddle discount id. Cashier-
        // Paddle's Checkout::options() carries no discount field, so we inject
        // `discount_id` into the returned overlay payload; the host's JS merges it
        // into Paddle.Checkout.open({ ...checkout_options, discountId }) and PADDLE
        // computes and applies the discount. We never compute the charged amount.
        //
        // Paddle has no per-subscribe trial parameter (a trial is a property of the
        // price), so there is no trial pass-through here — the app-side free-month
        // grant (ActivateFreeMonthAction) covers the gateway-less trial uniformly.
        $checkoutOptions = $this->withDiscount($checkout->options(), $options);

        // The host's checkout UI receives `mode: overlay` and feeds `checkout_options`
        // straight into Paddle.Checkout.open(), authenticating with `client_side_token`.
        // Divergent from the Stripe driver's ['checkout_url', 'session_id'] by design.
        return [
            'gateway' => 'paddle',
            'mode' => 'overlay',
            'checkout_options' => $checkoutOptions,
            'client_side_token' => $this->clientSideToken(),
            'return_url' => $checkout->getReturnUrl(),
        ];
    }

    /**
     * Merge a mapped Paddle discount id into the overlay checkout options (B5,
     * D-B5-1 hybrid), or return them unchanged when no code was supplied.
     *
     * Cashier-Paddle's Checkout::options() carries no discount field, so the id is
     * injected as `discountId` for the host's JS to pass to Paddle.Checkout.open();
     * PADDLE then computes and applies the discount. A pure function of its inputs
     * so it is testable without a network Checkout.
     *
     * @param  array<string, mixed>  $checkoutOptions
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withDiscount(array $checkoutOptions, array $options): array
    {
        $discountId = $options['paddle_discount_id'] ?? null;

        if (is_string($discountId) && $discountId !== '') {
            $checkoutOptions['discountId'] = $discountId;
        }

        return $checkoutOptions;
    }

    public function cancel(Tenant $tenant, bool $atPeriodEnd = true): void
    {
        $subscription = $this->billableFor($tenant)->subscription('default');

        if ($subscription === null) {
            return;
        }

        $atPeriodEnd ? $subscription->cancel() : $subscription->cancelNow();
    }

    /**
     * Programmatic refunds are deferred (decision D-B4-3). Paddle, as the merchant
     * of record, mediates refunds through its own dashboard/adjustments flow; the
     * MVP does not issue them from code. Fails loudly rather than silently no-op so
     * a caller never believes a refund happened.
     */
    public function refund(string $chargeReference, ?int $amount = null): void
    {
        throw new GatewayOperationException(
            'Paddle refunds are issued through Paddle (merchant of record); '.
            'programmatic refunds are not supported in this version.'
        );
    }

    public function subscriptionStatus(Tenant $tenant): string
    {
        $subscription = $this->billableFor($tenant)->subscription('default');

        if ($subscription === null) {
            return 'none';
        }

        // Paddle stores the lifecycle state in `status` (active/trialing/past_due/
        // paused/canceled) — not Stripe's `stripe_status`.
        $status = $subscription->status;

        return is_string($status) && $status !== '' ? $status : 'none';
    }

    /**
     * Verify a Paddle webhook signature before anything acts on it.
     *
     * Re-implemented here (rather than trusting Cashier-Paddle's route middleware)
     * so the contract method is self-sufficient when called outside that route —
     * the contract is non-negotiable that verification happens. Paddle's scheme:
     * the `Paddle-Signature` header carries `ts=<unix>;h1=<hex>`, and h1 is
     * HMAC-SHA256 over "{ts}:{rawBody}" with the signing secret. Reproducible
     * offline, exactly like the Stripe driver's constructEvent.
     */
    public function verifyWebhook(Request $request): array
    {
        $secret = $this->webhookSecret();

        if ($secret === null) {
            throw new WebhookVerificationException('Paddle webhook signing secret is not configured.');
        }

        $header = (string) $request->header('Paddle-Signature');
        [$timestamp, $hashes] = $this->parseSignature($header);

        if ($timestamp <= 0 || $hashes === []) {
            throw new WebhookVerificationException('Malformed or missing Paddle-Signature header.');
        }

        $tolerance = $this->signatureTolerance();
        if (time() > $timestamp + $tolerance) {
            throw new WebhookVerificationException('Paddle webhook signature has expired.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}:{$request->getContent()}", $secret);

        $matches = false;
        foreach ($hashes as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matches = true;
                break;
            }
        }

        if (! $matches) {
            throw new WebhookVerificationException('Invalid Paddle webhook signature.');
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            throw new WebhookVerificationException('Paddle webhook body is not valid JSON.');
        }

        return $payload;
    }

    /**
     * Parse the `Paddle-Signature` header (`ts=<unix>;h1=<hex>[;h1=<hex>]`) into the
     * timestamp and the list of candidate h1 hashes. Mirrors Cashier-Paddle's own
     * VerifyWebhookSignature parser so we accept exactly what Paddle sends.
     *
     * @return array{0:int,1:list<string>}
     */
    private function parseSignature(string $header): array
    {
        $timestamp = 0;
        $hashes = [];

        foreach (explode(';', $header) as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);

            if ($key === 'ts') {
                $timestamp = (int) $value;
            } elseif ($key === 'h1' && $value !== '' && count($hashes) < self::MAX_SIGNATURE_HASHES) {
                // Paddle sends a single h1 hash. Cap the list so an attacker-supplied
                // header cannot force unbounded hash_equals() work on an unverified
                // request.
                $hashes[] = $value;
            }
        }

        return [$timestamp, $hashes];
    }

    /**
     * Resolve a Cashier-Paddle Billable instance for the tenant. Same fast-path /
     * reload / fail-loud logic as the Stripe driver, but against the Paddle billable
     * model and the Paddle Billable trait.
     */
    private function billableFor(Tenant $tenant): Model
    {
        $class = $this->billableClass();

        if (! $this->classIsBillable($class)) {
            throw new GatewayNotConfiguredException(
                "paddle_billable_model [{$class}] does not use the Cashier-Paddle Billable trait. ".
                'Apply Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomerPaddle to a '.
                'Company subclass and point larafoundry-billing.paddle_billable_model at it.'
            );
        }

        if ($tenant instanceof $class) {
            return $tenant;
        }

        $model = (new $class)->newQuery()->whereKey($tenant->getTenantKey())->first();

        if (! $model instanceof Model) {
            throw new GatewayOperationException(
                "No billable [{$class}] found for tenant [{$tenant->getTenantKey()}]."
            );
        }

        return $model;
    }

    /**
     * The Paddle price id (`pri_xxx`) for a plan+period, from the per-plan map
     * `plans.definitions.<id>.gateways.paddle.<period>`. Missing => loud failure,
     * never a silent wrong/zero charge. Mirror of the Stripe driver.
     */
    private function priceId(string $planId, string $period): string
    {
        $price = $this->config->get("larafoundry-billing.plans.definitions.{$planId}.gateways.paddle.{$period}");

        if (! is_string($price) || $price === '') {
            throw new GatewayOperationException(
                "No Paddle price id for plan [{$planId}] period [{$period}]."
            );
        }

        return $price;
    }

    /**
     * Reverse of {@see priceId()}: map a Paddle price id back to our plan id, so the
     * webhook mirror can write `companies.plan_id`. Null when no plan claims it.
     */
    public function planIdForPrice(string $priceId): ?string
    {
        $definitions = $this->config->get('larafoundry-billing.plans.definitions', []);

        if (! is_array($definitions)) {
            return null;
        }

        foreach ($definitions as $planId => $definition) {
            $prices = $definition['gateways']['paddle'] ?? [];

            if (! is_array($prices)) {
                continue;
            }

            if (in_array($priceId, $prices, true)) {
                return (string) $planId;
            }
        }

        return null;
    }

    /**
     * Whether a class string uses Cashier-Paddle's Billable trait (recursively, so a
     * subclass mixing it in via {@see BillingCustomerPaddle} counts). False for the
     * gateway-agnostic core Company and for a Stripe-only Company.
     */
    private function classIsBillable(mixed $class): bool
    {
        return is_string($class)
            && $class !== ''
            && class_exists($class)
            && in_array(Billable::class, class_uses_recursive($class), true);
    }

    private function billableClass(): mixed
    {
        return $this->config->get('larafoundry-billing.paddle_billable_model');
    }

    /**
     * Paddle server API key: the add-on's own key, falling back to Cashier-Paddle's
     * `cashier.api_key` so a host that configured Cashier-Paddle directly still works.
     */
    private function apiKey(): ?string
    {
        $key = $this->config->get('larafoundry-billing.paddle.api_key')
            ?: $this->config->get('cashier.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function clientSideToken(): ?string
    {
        $token = $this->config->get('larafoundry-billing.paddle.client_side_token')
            ?: $this->config->get('cashier.client_side_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The webhook signing secret. NOTE Cashier-Paddle uses the FLAT
     * `cashier.webhook_secret` (unlike Stripe Cashier's nested `cashier.webhook.secret`),
     * so the fallback reads the flat key.
     */
    private function webhookSecret(): ?string
    {
        $secret = $this->config->get('larafoundry-billing.paddle.webhook.secret')
            ?: $this->config->get('cashier.webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function returnUrl(): ?string
    {
        $url = $this->config->get('larafoundry-billing.paddle.checkout.return_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * How old a signed webhook may be, in seconds — the replay window. Defaults to
     * 60s (Paddle's own middleware uses 5s; 60 leaves room for clock skew and a
     * delayed retry without a wide replay window on access-mutating events). A
     * non-positive or absurd configured value is clamped into [1, 300] rather than
     * disabling the freshness check, so replay protection can never be turned off.
     */
    private function signatureTolerance(): int
    {
        $tolerance = (int) $this->config->get('larafoundry-billing.paddle.signature_tolerance', 60);

        return max(1, min($tolerance, 300));
    }
}
