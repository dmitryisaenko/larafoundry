<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Listeners;

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver;
use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Mirrors Stripe subscription state into the core's `companies` columns
 * (sub-phase B3, the "source of truth / mirror" seam).
 *
 * Cashier's own webhook controller has already verified the Stripe signature and
 * reconciled its `subscriptions` table before firing {@see WebhookReceived}, so
 * this listener runs on a trusted, parsed payload. Cashier's table stays the
 * PROVIDER truth; these mirrored columns are the ACCESS truth the FREE core reads
 * via {@see SubscriptionState} — which
 * is why the core can gate access with no gateway installed. The webhook is the
 * only writer of the mirror.
 *
 * The columns are deliberately not `$fillable` on the core Company (server-only),
 * so writes go through `forceFill()->save()`.
 */
final class MirrorStripeSubscriptionState
{
    public function __construct(private readonly CashierStripeDriver $driver) {}

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? '';

        // Only subscription lifecycle events move the mirrored dates.
        if (! is_string($type) || ! str_starts_with($type, 'customer.subscription.')) {
            return;
        }

        $object = $event->payload['data']['object'] ?? [];

        if (! is_array($object)) {
            return;
        }

        $customerId = $object['customer'] ?? null;

        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        $billable = Cashier::findBillable($customerId);

        if (! $billable instanceof Model) {
            return;
        }

        $this->mirror($billable, $object, $type);
    }

    /**
     * Write the derived subscription state onto the billable's core columns.
     */
    private function mirror(Model $billable, array $object, string $type): void
    {
        $attributes = [];

        if ($type === 'customer.subscription.deleted') {
            // The subscription ended; access lapses at the next gate read.
            $attributes['subscription_ends_at'] = Carbon::now();
        } else {
            // created/updated: the paid-through boundary is cancel_at when a
            // cancellation is scheduled, otherwise the current period end.
            //
            // Under the Stripe basil API (pinned by Cashier 16: 2025-08-27.basil),
            // current_period_end was removed from the top-level subscription object
            // and lives only on each item — which is why Cashier itself reads it
            // from SubscriptionItem. Fall back to the item level so an active
            // subscription is not mirrored as ended (NULL) and access wrongly
            // revoked. Keep the top-level read first for pre-basil endpoints.
            $endTimestamp = $object['cancel_at']
                ?? $object['current_period_end']
                ?? $object['items']['data'][0]['current_period_end']
                ?? null;

            $attributes['subscription_ends_at'] = is_int($endTimestamp)
                ? Carbon::createFromTimestamp($endTimestamp)
                : null;
        }

        // Trial: mirror the trial end only while Stripe reports the sub as trialing,
        // so an ended/converted trial does not leave a stale future date behind.
        if ($type !== 'customer.subscription.deleted') {
            $trialTimestamp = $object['trial_end'] ?? null;
            $attributes['trial_ends_at'] = (($object['status'] ?? null) === 'trialing' && is_int($trialTimestamp))
                ? Carbon::createFromTimestamp($trialTimestamp)
                : null;
        }

        // Plan: reverse-map the Stripe price back to our plan id so the core's
        // plan_id stays current. Leave it untouched when no plan claims the price.
        $priceId = $object['items']['data'][0]['price']['id'] ?? null;

        if (is_string($priceId) && $priceId !== '') {
            $planId = $this->driver->planIdForPrice($priceId);

            if ($planId !== null) {
                $attributes['plan_id'] = $planId;
            }
        }

        $billable->forceFill($attributes)->save();

        // Drop the memoised SubscriptionState so the next access check re-reads the
        // freshly mirrored columns rather than a stale cached value.
        if (method_exists($billable, 'forgetSubscriptionState')) {
            $billable->forgetSubscriptionState();
        }
    }
}
