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
 * Extracted from the donor `AdminUsersFilter` (recon §B), trimmed to fields the
 * core ships: free-text search, registration window, verification state and
 * recent activity. Host-specific facets (age buckets, social) stay in the host.
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
}
