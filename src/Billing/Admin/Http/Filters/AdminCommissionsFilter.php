<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Filters;

use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;
use Illuminate\Support\Carbon;

/**
 * Query filter for the super-admin payout report (Ф4.4).
 *
 * Extends the reflection-based core {@see Filter}: each PUBLIC method maps to exactly one
 * request key. The date axis bounds `created_at` (the accrual time) in the app timezone so
 * month/year boundaries are deterministic. Private helpers are excluded from dispatch.
 */
class AdminCommissionsFilter extends Filter
{
    /**
     * Commission status — constrained to pending|approved|paid|void by the FormRequest.
     */
    public function status(string $value): void
    {
        $this->builder->where('status', $value);
    }

    /**
     * Affiliate id.
     */
    public function affiliate_id(string $value): void
    {
        $this->builder->where('affiliate_id', $value);
    }

    /**
     * Currency (ISO 4217 alpha-3), compared upper-cased.
     */
    public function currency(string $value): void
    {
        $this->builder->where('currency', strtoupper($value));
    }

    /**
     * Named date window over the accrual time. `all`/`range` apply no constraint (a manual
     * range is handled by date_from/date_to).
     */
    public function period(string $value): void
    {
        if ($value === 'all' || $value === 'range') {
            return;
        }

        $dates = $this->getPeriodDates($value);

        if ($dates['start'] !== null && $dates['end'] !== null) {
            $this->builder
                ->whereDate('created_at', '>=', $dates['start'])
                ->whereDate('created_at', '<=', $dates['end']);
        }
    }

    /**
     * Manual range lower bound (Y-m-d) on the accrual time.
     */
    public function date_from(string $value): void
    {
        $this->builder->whereDate('created_at', '>=', $value);
    }

    /**
     * Manual range upper bound (Y-m-d).
     */
    public function date_to(string $value): void
    {
        $this->builder->whereDate('created_at', '<=', $value);
    }

    /**
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
