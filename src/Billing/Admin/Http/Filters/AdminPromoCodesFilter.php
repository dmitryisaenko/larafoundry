<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Filters;

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;

/**
 * Query filter for the super-admin promo-code console (sub-phase B7b), ported from the
 * donor's `App\Http\Filters\AdminPromoCodesFilter`.
 *
 * Extends the reflection-based core {@see Filter}: one public method per request key,
 * the only way that key touches the query. The exhausted/expired branches compare
 * columns (`used_count >= max_uses`, `expires_at < now`) with bound parameters — no
 * user input reaches raw SQL.
 */
class AdminPromoCodesFilter extends Filter
{
    /**
     * Lifecycle facet: active | inactive | expired | exhausted. Mirrors
     * {@see PromoCode::getStatus()} so the
     * filter and the row badge agree.
     */
    public function status(string $value): void
    {
        match ($value) {
            'active' => $this->builder->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses')),
            'inactive' => $this->builder->where('is_active', false),
            'expired' => $this->builder->whereNotNull('expires_at')->where('expires_at', '<', now()),
            'exhausted' => $this->builder->whereNotNull('max_uses')->whereColumn('used_count', '>=', 'max_uses'),
            default => null,
        };
    }

    /**
     * Audience facet: personal (bound to a user) | general.
     */
    public function type(string $value): void
    {
        match ($value) {
            'personal' => $this->builder->whereNotNull('user_id'),
            'general' => $this->builder->whereNull('user_id'),
            default => null,
        };
    }

    /**
     * Code search (LIKE).
     */
    public function search(string $value): void
    {
        $this->builder->where('code', 'like', '%'.$value.'%');
    }
}
