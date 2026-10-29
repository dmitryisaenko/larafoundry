<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Dashboard;

use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers\PaymentController;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardMetricsService;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Computes the paid add-on's revenue metrics for the operator dashboard (B9).
 *
 * Feeds the revenue widget the add-on injects into the core dashboard through the
 * widget seam. Kept out of the provider so the SQL is testable and cache-ready,
 * mirroring the core's {@see DashboardMetricsService}.
 *
 * Multi-currency WITHOUT conversion (decision: B9 ships per-currency totals; an
 * FX converter, MRR/ARPU/churn are deferred — they cannot be summed across
 * currencies honestly without a rate source). It reuses the per-currency
 * `SUM(amount - discount_amount)` shape of the payments console
 * ({@see PaymentController::totals()}),
 * widened to four time windows in one query.
 *
 * Active-subscriptions counts the CORE companies whose subscription is still
 * live (`subscription_ends_at > now`), NOT a ledger row — the mirrored column is
 * the source of truth for "is this tenant subscribed", same as the access gate.
 */
class RevenueMetricsService
{
    /**
     * @return array{currencies: list<array<string, mixed>>, active_subscriptions: int}
     */
    public function summary(): array
    {
        return [
            'currencies' => $this->currencyTotals(),
            'active_subscriptions' => $this->activeSubscriptions(),
        ];
    }

    /**
     * Successful-payment totals per currency across four windows (this month,
     * last month, this year, all time), in minor units — one aggregate query.
     *
     * Only `success` payments count (pending/failed are excluded). Amounts are
     * net of discount and stay RAW per currency: no conversion, the Vue divides
     * by 100 and shows each currency on its own line.
     *
     * @return list<array{currency: string, this_month: int, last_month: int, year: int, all: int}>
     */
    protected function currencyTotals(): array
    {
        $monthStart = now()->startOfMonth()->toDateTimeString();
        $lastMonthStart = now()->startOfMonth()->subMonthNoOverflow()->toDateTimeString();
        $yearStart = now()->startOfYear()->toDateTimeString();

        $rows = CompanyPayment::query()
            ->where('payment_status', CompanyPayment::STATUS_SUCCESS)
            ->selectRaw(
                'currency, '
                .'SUM(amount - discount_amount) as all_total, '
                .'SUM(CASE WHEN paid_at >= ? THEN amount - discount_amount ELSE 0 END) as this_month, '
                .'SUM(CASE WHEN paid_at >= ? AND paid_at < ? THEN amount - discount_amount ELSE 0 END) as last_month, '
                .'SUM(CASE WHEN paid_at >= ? THEN amount - discount_amount ELSE 0 END) as this_year',
                [$monthStart, $lastMonthStart, $monthStart, $yearStart]
            )
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        return $rows->map(fn ($row): array => [
            'currency' => (string) $row->currency,
            'this_month' => (int) $row->this_month,
            'last_month' => (int) $row->last_month,
            'year' => (int) $row->this_year,
            'all' => (int) $row->all_total,
        ])->all();
    }

    /**
     * Count companies with a still-live PAID subscription (`subscription_ends_at`
     * in the future) — read from the core's mirrored column, never the ledger.
     *
     * Deliberately NOT the same as the core dashboard's "active companies" bucket:
     * this is "active subscriptions", so a trial is not counted (a trial is not a
     * subscription), and a company that happens to hold both a live trial and a
     * live subscription still counts here — it does have a paid subscription. The
     * trial-vs-subscription split lives on the companies widget; revenue counts
     * money-bearing subscriptions only.
     */
    protected function activeSubscriptions(): int
    {
        $model = $this->companyModel();

        return (int) $model::query()
            ->where('subscription_ends_at', '>', now())
            ->count();
    }

    /**
     * The billable company model: the add-on's billable model when a host set
     * one, else the core tenancy company model.
     *
     * @return class-string<Model>
     */
    protected function companyModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('larafoundry-billing.billable_model')
            ?: config('larafoundry.tenancy.company_model', Company::class);

        return $model;
    }
}
