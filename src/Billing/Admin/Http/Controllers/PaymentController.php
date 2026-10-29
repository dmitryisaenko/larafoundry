<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Filters\AdminPaymentsFilter;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests\AdminPaymentsFilterRequest;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Resources\AdminPaymentResource;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Policies\CompanyPaymentPolicy;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin payments console (sub-phase B7b), ported from the donor's
 * `App\Http\Controllers\Admin\PaymentController`.
 *
 * READ-ONLY: a filtered, paginated list of every company's payments with per-currency
 * totals. The donor converted those totals into display currencies via a
 * CurrencyConverter; the add-on does NOT — multi-currency conversion is B9, so totals
 * stay raw minor units PER currency. No refund, no edit (decision D-B7b-3).
 *
 * Reached only through the `larafoundry.admin` route gate; the {@see CompanyPaymentPolicy}
 * is the second lock at the action.
 */
class PaymentController extends Controller
{
    use HasPagination;

    public function __construct(
        protected CompanyPaymentPolicy $policy,
    ) {}

    public function index(AdminPaymentsFilterRequest $request): Response
    {
        abort_unless($this->policy->viewAny($request->user()), 403);

        $sortBy = (string) $request->input('sort_by', 'paid_at');
        $sortOrder = (string) $request->input('sort_order', 'desc');

        $payments = (new AdminPaymentsFilter($request))
            ->apply(CompanyPayment::query()->with(['company', 'user', 'promoCode']))
            ->orderBy($sortBy, $sortOrder)
            ->paginate($this->perPage())
            ->withQueryString();

        AdminPaymentResource::$latestPaymentIds = $this->latestPaymentIds($payments->getCollection()->pluck('company_id'));

        return Inertia::render('Admin/Payments/Index', [
            'payments' => AdminPaymentResource::collection($payments),
            'totals' => $this->totals($request),
            'pagination' => $this->getPaginationData($payments),
            'filters' => [
                'period' => $request->input('period', 'this_month'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'country' => $request->input('country'),
                'payment_status' => $request->input('payment_status'),
                'plan_id' => $request->input('plan_id'),
                'email' => $request->input('email'),
                'company_name' => $request->input('company_name'),
                'promo_code_id' => $request->input('promo_code_id'),
                'promo_code_name' => $this->promoCodeName($request->input('promo_code_id')),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
            // What to call the payer on screen: "Company" in `teams` mode, "Customer" in
            // `personal` — where the column holds people, not organisations.
            'subject_labels' => BillingSubject::labels(),
            'available_countries' => (array) config('larafoundry-billing.admin.available_countries', []),
            'available_plans' => $this->availablePlans(),
            'available_promo_codes' => $this->availablePromoCodes(),
        ]);
    }

    /**
     * Per-currency totals for the current filter (NO conversion, decision D-B7b-3).
     *
     * The same filter as the list, but pinned to one status: failed totals when the
     * `failed` status is selected, otherwise successful totals. Returned as a list of
     * {currency, total (minor units), is_failed} — the Vue divides by 100 and shows each
     * currency separately.
     *
     * @return list<array{currency: string, total: int, is_failed: bool}>
     */
    protected function totals(AdminPaymentsFilterRequest $request): array
    {
        $isFailed = $request->input('payment_status') === 'failed';

        $rows = (new AdminPaymentsFilter($request))
            ->apply(CompanyPayment::query())
            ->where('payment_status', $isFailed ? CompanyPayment::STATUS_FAILED : CompanyPayment::STATUS_SUCCESS)
            ->selectRaw('currency, SUM(amount - discount_amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $totals = [];

        foreach ($rows as $currency => $total) {
            $totals[] = [
                'currency' => (string) $currency,
                'total' => (int) $total,
                'is_failed' => $isFailed,
            ];
        }

        return $totals;
    }

    /**
     * The id of the most-recent successful payment for each company on the current page.
     *
     * One query ordered by paid_at desc, picking the first row per company in PHP —
     * collapses the donor's per-company subquery loop.
     *
     * @param  Collection<int, mixed>  $companyIds
     * @return list<int>
     */
    protected function latestPaymentIds($companyIds): array
    {
        $ids = $companyIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $rows = CompanyPayment::query()
            ->whereIn('company_id', $ids->all())
            ->where('payment_status', CompanyPayment::STATUS_SUCCESS)
            ->orderByDesc('paid_at')
            ->orderByDesc('id') // stable tiebreaker when two charges share a paid_at
            ->get(['id', 'company_id']);

        $seen = [];
        $latest = [];

        foreach ($rows as $row) {
            if (in_array($row->company_id, $seen, true)) {
                continue;
            }

            $seen[] = $row->company_id;
            $latest[] = (int) $row->id;
        }

        return $latest;
    }

    /**
     * Plans for the filter dropdown, from the add-on's plan source.
     *
     * @return list<array{id: string, name: string}>
     */
    protected function availablePlans(): array
    {
        return array_values(array_map(
            fn (PlanContract $plan): array => ['id' => $plan->id(), 'name' => $plan->name()],
            app(PlanRepositoryContract::class)->all(),
        ));
    }

    /**
     * Promo codes for the filter dropdown.
     *
     * @return list<array{id: int, code: string, description: ?string}>
     */
    protected function availablePromoCodes(): array
    {
        return PromoCode::query()
            ->orderBy('code')
            ->limit((int) config('larafoundry-billing.admin.available_promo_codes_limit', 200))
            ->get(['id', 'code', 'description'])
            ->map(fn (PromoCode $code): array => [
                'id' => (int) $code->id,
                'code' => $code->code,
                'description' => $code->description,
            ])
            ->all();
    }

    /**
     * The code string of the promo being filtered on, for the active-filter chip.
     */
    protected function promoCodeName(mixed $promoCodeId): ?string
    {
        if ($promoCodeId === null || $promoCodeId === '') {
            return null;
        }

        return PromoCode::query()->whereKey($promoCodeId)->value('code');
    }

    protected function perPage(): int
    {
        return (int) config('larafoundry-billing.admin.payments_per_page', 25);
    }
}
