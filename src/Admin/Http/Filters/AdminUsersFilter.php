<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Filters;

use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;

/**
 * Query filter for the super-admin user list (phase 2.3).
 *
 * Extends the reflection-based {@see Filter}: each public method here maps to a
 * request key and is the only way that key touches the query, so a crafted
 * query parameter can never invoke an unintended method. Empty values are
 * skipped by the base class.
 *
 * Extracted from the donor `AdminUsersFilter` (recon §B): free-text search,
 * registration window, verification state, recent activity, and the phase-3a
 * facets (country, phone verification, sex, age bucket). Each new enum facet
 * no-ops on an unrecognised value rather than collapsing the result set.
 */
class AdminUsersFilter extends Filter
{
    /**
     * Free-text search across name, lastname, email and phone.
     */
    public function search(string $value): void
    {
        $term = '%'.$value.'%';

        $this->builder->where(function ($query) use ($term) {
            $query->where('name', 'like', $term)
                ->orWhere('lastname', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('phone', 'like', $term);
        });
    }

    /**
     * Registration window: 'today' | 'month' | 'year'.
     */
    public function registered(string $value): void
    {
        $since = match ($value) {
            'today' => now()->startOfDay(),
            'month' => now()->subMonth(),
            'year' => now()->subYear(),
            default => null,
        };

        if ($since !== null) {
            $this->builder->where('created_at', '>=', $since);
        }
    }

    /**
     * Email verification state: 'verified' | 'unverified'.
     */
    public function emailVerified(string $value): void
    {
        $value === 'verified'
            ? $this->builder->whereNotNull('email_verified_at')
            : $this->builder->whereNull('email_verified_at');
    }

    /**
     * Account state: 'active' | 'blocked' | 'deleted'.
     */
    public function status(string $value): void
    {
        match ($value) {
            'blocked' => $this->builder->whereNotNull('user_blocked_at'),
            'deleted' => $this->builder->whereNotNull('user_deleted_at'),
            'active' => $this->builder
                ->whereNull('user_blocked_at')
                ->whereNull('user_deleted_at'),
            default => null,
        };
    }

    /**
     * Active within the last N hours (1 | 24 | 168).
     */
    public function recentActivity(string $value): void
    {
        $hours = (int) $value;

        if ($hours > 0) {
            $this->builder->where('last_activity_at', '>=', now()->subHours($hours));
        }
    }

    /**
     * Exact interface locale, e.g. 'en' | 'uk' (phase 7, Krokq).
     */
    public function locale(string $value): void
    {
        $this->builder->where('locale', $value);
    }

    /**
     * Sign-in type: 'oauth' (has a social provider) | 'password' (has none).
     *
     * Like the other enum filters (status, registered), an unrecognised value is
     * a no-op rather than silently collapsing into one branch — so a stale or
     * crafted `?authType=x` does not quietly hide every OAuth user.
     */
    public function authType(string $value): void
    {
        match ($value) {
            'oauth' => $this->builder->whereNotNull('provider_name'),
            'password' => $this->builder->whereNull('provider_name'),
            default => null,
        };
    }

    /**
     * Exact country match (phase 3a). Always available on the backend; the front
     * shows it as a plain text filter (the core ships no country registry).
     */
    public function country(string $value): void
    {
        $this->builder->where('country', trim($value));
    }

    /**
     * Phone verification state: 'verified' | 'unverified' (phase 3a).
     *
     * Mirrors {@see emailVerified}. Backend-safe on an unpopulated column; the
     * front only surfaces it when the `phone` column token is enabled.
     */
    public function phoneVerified(string $value): void
    {
        $value === 'verified'
            ? $this->builder->whereNotNull('phone_verified_at')
            : $this->builder->whereNull('phone_verified_at');
    }

    /**
     * Exact sex match (phase 3a).
     *
     * A no-op for an empty value (handled by the base) — any concrete value is an
     * exact match, so an unknown value simply returns no rows rather than an error
     * or a collapsed branch. Surfaced on the front only when the `sex` token is on.
     */
    public function sex(string $value): void
    {
        $this->builder->where('sex', $value);
    }

    /**
     * Age bucket over `birth_date` (phase 3a).
     *
     * Maps a coarse bucket to a birth-date window rather than computing age in
     * SQL (portable across drivers). Like the other enum filters, an unrecognised
     * bucket is a no-op — never a query that hides everyone. Surfaced on the front
     * only when the `age` token is on.
     */
    public function ageRange(string $value): void
    {
        // [minAge, maxAge]; a null max is an open-ended top bucket. The buckets
        // are disjoint — '46-59' stops at 59 so a 60-year-old lands only in the
        // '60+' bucket, never in both.
        $buckets = [
            '18-25' => [18, 25],
            '26-35' => [26, 35],
            '36-45' => [36, 45],
            '46-59' => [46, 59],
            '60+' => [60, null],
        ];

        if (! isset($buckets[$value])) {
            return;
        }

        [$minAge, $maxAge] = $buckets[$value];

        // Age >= minAge  ->  born on or before (today - minAge years).
        $this->builder->where('birth_date', '<=', now()->subYears($minAge)->endOfDay());

        // Age <= maxAge  ->  born after (today - (maxAge + 1) years). Open-ended
        // top bucket ('60+') skips the lower bound on birth_date.
        if ($maxAge !== null) {
            $this->builder->where('birth_date', '>', now()->subYears($maxAge + 1)->endOfDay());
        }
    }
}
