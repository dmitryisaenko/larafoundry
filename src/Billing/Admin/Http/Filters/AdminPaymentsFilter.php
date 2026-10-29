<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Filters;

use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests\AdminPaymentsFilterRequest;
use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Query filter for the super-admin payments console (sub-phase B7b), ported from the
 * donor's `App\Http\Filters\AdminPaymentsFilter`.
 *
 * Extends the reflection-based core {@see Filter}: each PUBLIC method maps to exactly
 * one request key and is the only way that key touches the query, so a crafted
 * parameter can never invoke an unintended method. Empty / non-scalar values are
 * skipped by the base class (an `?email[]=x` array cannot 500 the page).
 *
 * Unlike the donor, this does NOT override `apply()` to inject a default period — the
 * core base drives the dispatch and excludes inherited methods from request input. The
 * smart default (`all` when filtering by promo code, else `this_month`) is merged in by
 * {@see AdminPaymentsFilterRequest::prepareForValidation()},
 * so `period()` here always receives a value.
 */
class AdminPaymentsFilter extends Filter
{
    /**
     * Date window. `all`/`range` apply no date constraint (a manual range is handled by
     * date_from/date_to); a named period bounds COALESCE(paid_at, created_at) so failed
     * rows (no paid_at) still fall in the window by their created_at.
     */
    public function period(string $value): void
    {
        if ($value === 'all' || $value === 'range') {
            return;
        }

        $dates = $this->getPeriodDates($value);

        if ($dates['start'] !== null && $dates['end'] !== null) {
            $this->builder
                ->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '>=', $dates['start'])
                ->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '<=', $dates['end']);
        }
    }

    /**
     * Manual range lower bound (Y-m-d), on COALESCE(paid_at, created_at).
     */
    public function date_from(string $value): void
    {
        $this->builder->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '>=', $value);
    }

    /**
     * Manual range upper bound (Y-m-d).
     */
    public function date_to(string $value): void
    {
        $this->builder->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '<=', $value);
    }

    /**
     * Company country (ISO alpha-2).
     */
    public function country(string $value): void
    {
        $this->builder->whereHas('company', fn ($q) => $q->where('country', $value));
    }

    /**
     * Payment status — constrained to success|failed by the FormRequest.
     */
    public function payment_status(string $value): void
    {
        $this->builder->where('payment_status', $value);
    }

    /**
     * Plan identifier (Company.plan_id string).
     */
    public function plan_id(string $value): void
    {
        $this->builder->where('plan_id', $value);
    }

    /**
     * Payer email (LIKE on the related user).
     */
    public function email(string $value): void
    {
        $this->builder->whereHas('user', fn ($q) => $q->where('email', 'like', '%'.$value.'%'));
    }

    /**
     * Company name (LIKE on the related company).
     */
    public function company_name(string $value): void
    {
        $this->builder->whereHas('company', fn ($q) => $q->where('name', 'like', '%'.$value.'%'));
    }

    /**
     * Promo code id.
     */
    public function promo_code_id(string $value): void
    {
        $this->builder->where('promo_code_id', $value);
    }

    /**
     * Start/end Carbon dates for a named period, in the app timezone so month/year
     * boundaries are deterministic regardless of the server's default tz.
     *
     * @return array{start: ?Carbon, end: ?Carbon}
     */
    private function getPeriodDates(string $period): array
    {
        $now = Carbon::now(config('app.timezone'));

        $periods = [
            'this_month' => ['start' => $now->copy()->startOfMonth(), 'end' => $now->copy()->endOfMonth()],
            'last_month' => ['start' => $now->copy()->subMonth()->startOfMonth(), 'end' => $now->copy()->subMonth()->endOfMonth()],
            'year' => ['start' => $now->copy()->startOfYear(), 'end' => $now->copy()->endOfYear()],
        ];

        return $periods[$period] ?? ['start' => null, 'end' => null];
    }
}
