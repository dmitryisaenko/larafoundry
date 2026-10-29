<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Gateways;

use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayNotConfiguredException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayOperationException;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * The real Stripe gateway (sub-phase B3), implementing the core's
 * {@see PaymentGatewayInterface} on top of Laravel Cashier.
 *
 * Responsibilities and boundaries:
 *
 *  - Money mechanics only — subscribe / cancel / refund / status / webhook
 *    verification. Tax and invoicing are out (Stripe makes the host the merchant
 *    of record, which owns VAT/receipts) per the contract docblock.
 *  - The contract speaks in terms of a {@see Tenant}, but Cashier's methods live
 *    on a Billable model. The driver bridges the two via {@see billableFor()},
 *    resolving the configured `billable_model` and failing loudly if it is not
 *    actually Billable (the default core Company is not).
 *  - subscribe() uses hosted Stripe Checkout (decision D-B3-2): it returns a
 *    redirect url + session id; the host sends the buyer there. The completed
 *    payment arrives back as a webhook, which the add-on's listener mirrors into
 *    the core's `companies` columns — the gate reads those, never this driver.
 *
 * Registered against the core PaymentGatewayManager by the service provider from
 * `config('larafoundry-billing.gateways.stripe')`.
 */
final class CashierStripeDriver implements PaymentGatewayInterface
{
    public function __construct(private readonly Config $config) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return $this->secret() !== null
            && $this->classIsBillable($this->billableClass());
    }

    public function subscribe(Tenant $tenant, string $planId, string $period, array $options = []): array
    {
        $billable = $this->billableFor($tenant);
        $priceId = $this->priceId($planId, $period);

        $builder = $billable->newSubscription('default', $priceId);

        // Optional free trial (B5, decision D-B5-2 gateway pass-through): a trial on a
        // PAID plan is set on the builder before checkout. `trial_until` (a date)
        // wins over `trial_days` (a count) when both are passed. The app-side
        // free-month grant is a separate, gateway-less path (ActivateFreeMonthAction).
        if (! empty($options['trial_until'])) {
            $builder->trialUntil($options['trial_until']);
        } elseif (! empty($options['trial_days'])) {
            $builder->trialDays((int) $options['trial_days']);
        }

        $checkoutOptions = [
            'success_url' => $options['success_url'] ?? $this->checkoutUrl('success_url'),
            'cancel_url' => $options['cancel_url'] ?? $this->checkoutUrl('cancel_url'),
        ];

        // A catalogue promo code resolved by our registry (B5, D-B5-1 hybrid): the
        // RedeemPromoCodeAction maps a registry code to a Stripe coupon / promotion
        // code id, which we attach as a Checkout `discounts` entry so STRIPE computes
        // and applies the discount. We never compute the charged amount ourselves.
        //
        // Stripe Checkout forbids `discounts` together with `allow_promotion_codes`,
        // so it is one or the other: a mapped catalogue discount takes precedence;
        // otherwise, if the caller opts in, we let the buyer type their own promotion
        // code on the hosted page.
        $discount = $this->checkoutDiscount($options);

        if ($discount !== null) {
            $checkoutOptions['discounts'] = [$discount];
        } elseif (! empty($options['promo'])) {
            $builder->allowPromotionCodes();
        }

        $checkout = $builder->checkout($checkoutOptions);

        return [
            'checkout_url' => $checkout->url,
            'session_id' => $checkout->id,
        ];
    }

    /**
     * Build the single Stripe Checkout `discounts` entry from a mapped catalogue
     * code, or null when none was supplied.
     *
     * Accepts a promotion-code id (`promo_xxx`, preferred — it carries usage rules)
     * or a raw coupon id (`<coupon>`); Stripe accepts either key but not both in one
     * entry. Returns null so the caller falls back to `allow_promotion_codes`.
     *
     * @param  array<string, mixed>  $options
     * @return array{promotion_code:string}|array{coupon:string}|null
     */
    private function checkoutDiscount(array $options): ?array
    {
        $promotionCode = $options['stripe_promotion_code'] ?? null;

        if (is_string($promotionCode) && $promotionCode !== '') {
            return ['promotion_code' => $promotionCode];
        }

        $coupon = $options['stripe_coupon'] ?? null;

        if (is_string($coupon) && $coupon !== '') {
            return ['coupon' => $coupon];
        }

        return null;
    }

    public function cancel(Tenant $tenant, bool $atPeriodEnd = true): void
    {
        $subscription = $this->billableFor($tenant)->subscription('default');

        if ($subscription === null) {
            return;
        }

        $atPeriodEnd ? $subscription->cancel() : $subscription->cancelNow();
    }

    public function refund(string $chargeReference, ?int $amount = null): void
    {
        $params = ['payment_intent' => $chargeReference];

        if ($amount !== null) {
            $params['amount'] = $amount;
        }

        Cashier::stripe()->refunds->create($params);
    }

    public function subscriptionStatus(Tenant $tenant): string
    {
        $subscription = $this->billableFor($tenant)->subscription('default');

        if ($subscription === null) {
            return 'none';
        }

        $status = $subscription->stripe_status;

        return is_string($status) && $status !== '' ? $status : 'none';
    }

    public function verifyWebhook(Request $request): array
    {
        $secret = $this->webhookSecret();

        if ($secret === null) {
            throw new WebhookVerificationException('Stripe webhook signing secret is not configured.');
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException $e) {
            throw new WebhookVerificationException('Invalid Stripe webhook signature.', previous: $e);
        }

        // Stripe\Event is a StripeObject; toArray() returns the deep associative
        // payload (recursive by default in stripe-php 17) the core contract promises.
        return $event->toArray();
    }

    /**
     * Resolve a Cashier Billable instance for the tenant.
     *
     * Fast path: the tenant already IS the configured billable model (the common
     * host wiring where company_model === billable_model), so it is returned as-is
     * without a query. Otherwise it is re-loaded by primary key from the billable
     * class, guaranteeing a model that carries the Billable trait and the Cashier
     * columns. A non-Billable class (the default core Company) or a missing record
     * fails loudly rather than fataling on an undefined Cashier method.
     */
    private function billableFor(Tenant $tenant): Model
    {
        $class = $this->billableClass();

        if (! $this->classIsBillable($class)) {
            throw new GatewayNotConfiguredException(
                "billable_model [{$class}] does not use the Cashier Billable trait. ".
                'Apply Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer to your '.
                'Company subclass and point larafoundry-billing.billable_model at it.'
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
     * The Stripe price id (`price_xxx`) for a plan+period, from the per-plan map
     * `plans.definitions.<id>.gateways.stripe.<period>` (decision D-B3-1). Missing
     * => loud failure, never a silent wrong/zero charge.
     */
    private function priceId(string $planId, string $period): string
    {
        $price = $this->config->get("larafoundry-billing.plans.definitions.{$planId}.gateways.stripe.{$period}");

        if (! is_string($price) || $price === '') {
            throw new GatewayOperationException(
                "No Stripe price id for plan [{$planId}] period [{$period}]."
            );
        }

        return $price;
    }

    /**
     * Reverse of {@see priceId()}: map a Stripe price id back to our plan id, so
     * the webhook mirror can write `companies.plan_id`. Null when no plan claims
     * the price (e.g. a price created directly in Stripe outside the catalogue).
     */
    public function planIdForPrice(string $priceId): ?string
    {
        $definitions = $this->config->get('larafoundry-billing.plans.definitions', []);

        if (! is_array($definitions)) {
            return null;
        }

        foreach ($definitions as $planId => $definition) {
            $prices = $definition['gateways']['stripe'] ?? [];

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
     * Whether a class string uses Cashier's Billable trait (recursively, so a
     * subclass that mixes it in via {@see BillingCustomer}
     * counts). False for the gateway-agnostic core Company.
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
        return $this->config->get('larafoundry-billing.billable_model');
    }

    /**
     * The Stripe API secret: the add-on's own key, falling back to Cashier's
     * `cashier.secret` so a host that configured Cashier directly still works.
     */
    private function secret(): ?string
    {
        $secret = $this->config->get('larafoundry-billing.stripe.secret')
            ?: $this->config->get('cashier.secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function webhookSecret(): ?string
    {
        $secret = $this->config->get('larafoundry-billing.stripe.webhook.secret')
            ?: $this->config->get('cashier.webhook.secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function checkoutUrl(string $key): ?string
    {
        $url = $this->config->get("larafoundry-billing.stripe.checkout.{$key}");

        return is_string($url) && $url !== '' ? $url : null;
    }
}
