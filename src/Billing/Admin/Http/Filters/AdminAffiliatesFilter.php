<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Filters;

use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;

/**
 * Query filter for the super-admin affiliates list (Ф4.4).
 *
 * Extends the reflection-based core {@see Filter}: each PUBLIC method maps to exactly one
 * request key and is the only way that key touches the query, so a crafted parameter can
 * never invoke an unintended method, and empty / non-scalar values are skipped by the base.
 */
class AdminAffiliatesFilter extends Filter
{
    /**
     * Partner status — constrained to active|disabled|pending by the FormRequest.
     */
    public function status(string $value): void
    {
        $this->builder->where('status', $value);
    }

    /**
     * Referral code (LIKE).
     */
    public function code(string $value): void
    {
        $this->builder->where('code', 'like', '%'.$value.'%');
    }

    /**
     * Partner email (LIKE on the related user).
     */
    public function email(string $value): void
    {
        $this->builder->whereHas('user', fn ($q) => $q->where('email', 'like', '%'.$value.'%'));
    }
}
