<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Filters;

use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionStatus;
use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;
use Illuminate\Support\Carbon;

/**
 * Query filter for the super-admin company list (phase 3.3).
 *
 * Extends the reflection-based {@see Filter}: each public method maps to one
 * request key and is the only way that key touches the query, so a crafted
 * parameter can never invoke an unintended method. Empty values are skipped by
 * the base class.
 *
 * Extracted from the donor `AdminCompaniesFilter` (recon §E), trimmed to what the
 * FREE core actually has: free-text search, country, created-at window, and a
 * subscription-status facet derived from the billing columns (phase 3.1). The
 * donor's payment/revenue facets are NOT here — payment records live in the paid
 * billing add-on, so the core never filters by money it does not store.
 */
class AdminCompaniesFilter extends Filter
{
    /**
     * Free-text search across the company name/slug and its owner's email/name.
     */
    public function search(string $value): void
    {
        $term = '%'.$value.'%';

        $this->builder->where(function ($query) use ($term) {
            $query->where('name', 'like', $term)
                ->orWhere('slug', 'like', $term)
                ->orWhereHas('createdBy', function ($owner) use ($term) {
                    $owner->where('email', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('lastname', 'like', $term);
                });
        });
    }

    /**
     * Filter by ISO country code.
     */
    public function country(string $value): void
    {
        $this->builder->where('country', $value);
    }

    /**
     * Creation window lower bound (Y-m-d).
     */
    public function dateFrom(string $value): void
    {
        if ($date = $this->parseDate($value)) {
            $this->builder->where('created_at', '>=', $date->startOfDay());
        }
    }

    /**
     * Creation window upper bound (Y-m-d).
     */
    public function dateTo(string $value): void
    {
        if ($date = $this->parseDate($value)) {
            $this->builder->where('created_at', '<=', $date->endOfDay());
        }
    }

    /**
     * Subscription status facet, computed from the billing columns.
     *
     * The five statuses mirror {@see SubscriptionStatus} exactly so the badge in
     * the list and this filter agree. The classification is date math on
     * `trial_ends_at` / `subscription_ends_at`, expressed here as SQL so it pages
     * in the database rather than in PHP. The "expiring" window is the same
     * config value the classifier reads.
     */
    public function subscriptionStatus(string $value): void
    {
        $now = now();

        match ($value) {
            SubscriptionStatus::ON_TRIAL => $this->builder->where('trial_ends_at', '>', $now),

            SubscriptionStatus::ACTIVE => $this->builder
                ->where(fn ($q) => $q->where('trial_ends_at', '<=', $now)->orWhereNull('trial_ends_at'))
                ->where('subscription_ends_at', '>', $now->copy()->addDays($this->expiringDays())),

            SubscriptionStatus::EXPIRING => $this->builder
                ->where(fn ($q) => $q->where('trial_ends_at', '<=', $now)->orWhereNull('trial_ends_at'))
                ->where('subscription_ends_at', '>', $now)
                ->where('subscription_ends_at', '<=', $now->copy()->addDays($this->expiringDays())),

            SubscriptionStatus::EXPIRED => $this->builder
                ->where(fn ($q) => $q->where('trial_ends_at', '<=', $now)->orWhereNull('trial_ends_at'))
                ->where(fn ($q) => $q->where('subscription_ends_at', '<=', $now)->orWhereNull('subscription_ends_at'))
                ->where(fn ($q) => $q->whereNotNull('subscription_ends_at')->orWhereNotNull('trial_ends_at')),

            SubscriptionStatus::NEVER_ACTIVATED => $this->builder
                ->whereNull('trial_ends_at')
                ->whereNull('subscription_ends_at'),

            default => null,
        };
    }

    /**
     * Account state facet: 'active' | 'blocked'.
     */
    public function blockState(string $value): void
    {
        match ($value) {
            'blocked' => $this->builder->whereNotNull('company_blocked_at'),
            'active' => $this->builder->whereNull('company_blocked_at'),
            default => null,
        };
    }

    /**
     * Parse a Y-m-d request value to a Carbon date, or null on garbage.
     */
    protected function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function expiringDays(): int
    {
        return (int) config('larafoundry.admin.subscription_expiring_within_days', 7);
    }
}
