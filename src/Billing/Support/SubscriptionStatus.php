<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use DateTimeInterface;
use Dmitryisaenko\LaraFoundry\Admin\Http\Filters\AdminCompaniesFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Classifies a tenant's subscription into a single human label for the admin
 * console (phase 3.3).
 *
 * `SubscriptionState` (phase 3.1) answers the binary access question
 * (trial-or-subscription in the future → has access). The console needs a
 * richer, display-only vocabulary — is it on trial, expiring soon, long
 * expired, never activated — so an operator can scan and filter the list.
 *
 * This is the ONE place that vocabulary is computed, shared by
 * {@see AdminCompaniesFilter} (the
 * `subscription_status` filter) and the company resource (the status badge), so
 * the badge and the filter can never disagree. It reuses the same billing
 * columns and the same future-comparison as SubscriptionState — it does not
 * re-implement "is this date in the future", it asks the value object.
 *
 * It is read-only and says nothing about managing a subscription: changing a
 * plan or extending a period is the paid add-on's job, not the core's.
 */
class SubscriptionStatus
{
    public const ON_TRIAL = 'on_trial';

    public const ACTIVE = 'active';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    public const NEVER_ACTIVATED = 'never_activated';

    /**
     * Classify the billable model's current subscription status.
     *
     * Order of precedence:
     *  - a live trial            → on_trial
     *  - an active subscription  → active, narrowed to "expiring" when its end
     *                              date is within the configured window
     *  - a past subscription end → expired
     *  - nothing ever set        → never_activated
     */
    public static function for(Model $billable): string
    {
        $state = SubscriptionState::for($billable);

        if ($state->isOnTrial()) {
            return self::ON_TRIAL;
        }

        if ($state->hasActiveSubscription()) {
            return self::isExpiringSoon($billable->getAttribute('subscription_ends_at'))
                ? self::EXPIRING
                : self::ACTIVE;
        }

        // No live trial or subscription. Distinguish "lapsed" (there was an end
        // date, now in the past) from "never activated" (no billing date at all)
        // — the operator treats a churned company differently from a fresh one.
        if ($billable->getAttribute('subscription_ends_at') !== null
            || $billable->getAttribute('trial_ends_at') !== null) {
            return self::EXPIRED;
        }

        return self::NEVER_ACTIVATED;
    }

    /**
     * Whether an active subscription's end date falls inside the "expiring"
     * window (config `larafoundry.admin.subscription_expiring_within_days`).
     */
    protected static function isExpiringSoon(mixed $subscriptionEndsAt): bool
    {
        if (! $subscriptionEndsAt instanceof DateTimeInterface) {
            return false;
        }

        $days = (int) config('larafoundry.admin.subscription_expiring_within_days', 7);

        return Carbon::instance($subscriptionEndsAt)->lessThanOrEqualTo(now()->addDays($days));
    }
}
