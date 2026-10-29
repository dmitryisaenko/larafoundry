<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Listeners;

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver;
use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Events\WebhookReceived;

/**
 * Mirrors Paddle subscription state into the core's `companies` columns (sub-phase
 * B4) — the Paddle sibling of {@see MirrorStripeSubscriptionState}.
 *
 * It writes the SAME gateway-agnostic columns (`subscription_ends_at`,
 * `trial_ends_at`, `plan_id`) the Stripe mirror writes, which live in the CORE
 * package migration. So whether a tenant pays through Stripe or Paddle, the FREE
 * core's gate reads identical access truth via {@see SubscriptionState} and never
 * learns which gateway answered.
 *
 * Cashier-Paddle's webhook controller has already verified the Paddle signature
 * before firing {@see WebhookReceived}, so this runs on a trusted, parsed payload.
 * The provider only registers this listener when a signing secret is present
 * (fail-closed), so an unauthenticated body can never reach it.
 *
 * Deltas from the Stripe mirror, all from Paddle's payload shape:
 *  - the event key is `event_type` (e.g. `subscription.updated`), not `type`;
 *  - the subscription resource is at `data` (not `data.object`);
 *  - the billable is linked by `data.customer_id` resolved through Paddle's own
 *    polymorphic `customers` table, not a column on the company;
 *  - dates are RFC 3339 strings parsed with {@see Carbon::parse}, not unix ints.
 */
final class MirrorPaddleSubscriptionState
{
    public function __construct(private readonly CashierPaddleDriver $driver) {}

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['event_type'] ?? '';

        // Only subscription lifecycle events move the mirrored dates.
        if (! is_string($type) || ! str_starts_with($type, 'subscription.')) {
            return;
        }

        $data = $event->payload['data'] ?? [];

        if (! is_array($data)) {
            return;
        }

        $customerId = $data['customer_id'] ?? null;

        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        // Resolves through Paddle's `customers` table (paddle_id -> billable morph).
        $billable = Cashier::findBillable($customerId);

        if (! $billable instanceof Model) {
            return;
        }

        $this->mirror($billable, $data, $type);
    }

    /**
     * Write the derived subscription state onto the billable's core columns.
     */
    private function mirror(Model $billable, array $data, string $type): void
    {
        $attributes = [];

        if ($type === 'subscription.canceled') {
            // The subscription ended; access lapses at the next gate read.
            $attributes['subscription_ends_at'] = Carbon::now();
        } else {
            // The paid-through boundary: a scheduled cancel/pause runs to its
            // effective date, otherwise the current billing period end. Paddle sends
            // these as RFC 3339 strings.
            $scheduled = $data['scheduled_change'] ?? null;
            $scheduledAction = is_array($scheduled) ? ($scheduled['action'] ?? null) : null;

            $endsAt = (in_array($scheduledAction, ['cancel', 'pause'], true) && is_array($scheduled))
                ? ($scheduled['effective_at'] ?? null)
                : ($data['current_billing_period']['ends_at'] ?? null);

            $attributes['subscription_ends_at'] = is_string($endsAt) && $endsAt !== ''
                ? Carbon::parse($endsAt)
                : null;
        }

        // Trial: mirror the trial end only while Paddle reports the sub as trialing,
        // so an ended/converted trial does not leave a stale future date behind.
        //
        // Source is the item's `trial_dates.ends_at` — the actual trial boundary —
        // NOT Cashier-Paddle's own controller field `next_billed_at` (the next charge
        // date). For a trialing subscription the two coincide, but trial_dates.ends_at
        // is the semantically correct boundary for the access gate.
        if ($type !== 'subscription.canceled') {
            $status = $data['status'] ?? null;
            $trialEnd = $data['items'][0]['trial_dates']['ends_at'] ?? null;

            $attributes['trial_ends_at'] = ($status === 'trialing' && is_string($trialEnd) && $trialEnd !== '')
                ? Carbon::parse($trialEnd)
                : null;
        }

        // Plan: reverse-map the Paddle price back to our plan id so the core's
        // plan_id stays current. Leave it untouched when no plan claims the price.
        $priceId = $data['items'][0]['price']['id'] ?? null;

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
