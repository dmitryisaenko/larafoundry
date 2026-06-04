<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reads a tenant's subscription state from its billing columns (phase 3.1).
 *
 * A small value object so the access logic lives in ONE place that both
 * `Company::hasAccess()` and the `HasSubscription` trait delegate to — the donor
 * scattered `trial_ends_at->isFuture()` / `subscription_ends_at->isFuture()`
 * across models and controllers; we don't.
 *
 * It reads the columns the core's migration adds (`trial_ends_at`,
 * `subscription_ends_at`). It does NOT consult the gateway — the gateway is the
 * source of truth for the provider, these mirrored columns are the source of
 * truth for access, and the add-on's webhook keeps them in sync. That separation
 * is what lets the FREE core gate access with no gateway installed.
 */
class SubscriptionState
{
    private function __construct(
        private readonly ?DateTimeInterface $trialEndsAt,
        private readonly ?DateTimeInterface $subscriptionEndsAt,
    ) {}

    /**
     * Build the state from a billable model's columns.
     *
     * Typed to Eloquent's Model (not the bare Tenant contract) because reading
     * the date columns is an Eloquent concern — and both billing carriers are
     * Models: the Company (teams mode) and, if a host ever bills per user, the
     * User (personal mode). Reads via getAttribute so it works whether the host
     * casts the columns to dates or leaves them as strings; non-date / empty
     * values normalise to null (treated as "no trial / no subscription" —
     * fail-closed, never a crash).
     */
    public static function for(Model $billable): self
    {
        return new self(
            self::toDate($billable->getAttribute('trial_ends_at')),
            self::toDate($billable->getAttribute('subscription_ends_at')),
        );
    }

    /**
     * Whether a trial is currently running (end date in the future).
     */
    public function isOnTrial(): bool
    {
        return $this->isFuture($this->trialEndsAt);
    }

    /**
     * Whether a paid subscription is currently active (end date in the future).
     */
    public function hasActiveSubscription(): bool
    {
        return $this->isFuture($this->subscriptionEndsAt);
    }

    /**
     * Whether the given end date is set and still in the future.
     *
     * One home for the future-comparison so the trial and subscription checks
     * (and any later one) cannot drift — the donor scattered this `->isFuture()`
     * across models; keeping it single is the whole point of this class.
     */
    private function isFuture(?DateTimeInterface $date): bool
    {
        return $date !== null && Carbon::instance($date)->isFuture();
    }

    /**
     * The access decision: a live trial OR an active subscription.
     *
     * This is what `Company::hasAccess()` returns when billing is ENABLED. When
     * billing is disabled the gate short-circuits to true before reaching here
     * (the free core stays fully usable) — see Company::hasAccess().
     */
    public function hasAccess(): bool
    {
        return $this->isOnTrial() || $this->hasActiveSubscription();
    }

    /**
     * Normalise a raw column value to a date, or null.
     *
     * Accepts a DateTimeInterface (already cast) or a parseable string; anything
     * else (null, empty, garbage) becomes null so the state degrades to
     * fail-closed rather than throwing on a malformed value.
     */
    private static function toDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
