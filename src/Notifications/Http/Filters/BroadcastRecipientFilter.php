<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Http\Filters;

use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;

/**
 * Selects the audience for a super-admin broadcast (phase 4.1).
 *
 * Extends the reflection-based {@see Filter}: each public method maps to a
 * stored `recipient_filters` key, so a value can only reach the query through
 * its declared method. The core ships domain-independent facets — verification,
 * recent activity and RBAC role. Demographic facets (country, age, sex) are
 * host-specific and stay in the host (decision 5).
 */
class BroadcastRecipientFilter extends Filter
{
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
     * Restrict to users holding a given role slug (RBAC, any tenant).
     */
    public function role(string $value): void
    {
        $this->builder->whereHas('roles', fn ($query) => $query->where('slug', $value));
    }
}
