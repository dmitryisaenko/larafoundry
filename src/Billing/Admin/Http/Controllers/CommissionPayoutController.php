<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Filters\AdminCommissionsFilter;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests\AdminCommissionsFilterRequest;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests\PayoutActionRequest;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Resources\AdminCommissionResource;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Policies\AffiliatePolicy;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super-admin payout console (Ф4.4): the commission report (filtered list + per-currency
 * totals by status, NO cross-currency conversion), the manual payout actions
 * (approve -> mark-paid, plus a manual void for refunds the same-invoice clawback cannot
 * catch), and a CSV export of the current report. Payout is manual on purpose — the add-on
 * has no money-out PSP; it tells the operator who is owed what, per currency.
 */
class CommissionPayoutController extends Controller
{
    use HasPagination;

    public function __construct(
        protected AffiliatePolicy $policy,
    ) {}

    public function index(AdminCommissionsFilterRequest $request): Response
    {
        abort_unless($this->policy->viewAny($request->user()), 403);

        $sortBy = (string) $request->input('sort_by', 'created_at');
        $sortOrder = (string) $request->input('sort_order', 'desc');

        $commissions = (new AdminCommissionsFilter($request))
            ->apply(AffiliateCommission::query()->with('affiliate.user'))
            ->orderBy($sortBy, $sortOrder)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return Inertia::render('Admin/Payouts/Index', [
            'commissions' => AdminCommissionResource::collection($commissions),
            'totals' => $this->totals($request),
            'pagination' => $this->getPaginationData($commissions),
            'filters' => [
                'status' => $request->input('status'),
                'affiliate_id' => $request->input('affiliate_id'),
                'currency' => $request->input('currency'),
                'period' => $request->input('period', 'all'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
            'available_affiliates' => $this->availableAffiliates(),
            // "Company" in `teams` mode, "Customer" in `personal` — the referred tenant
            // is a person there, and the console must not call it an organisation.
            'subject_labels' => BillingSubject::labels(),
        ]);
    }

    /**
     * Per-currency totals broken down by status for the current filter (NO conversion).
     *
     * @return list<array{currency: string, status: string, total: int, count: int}>
     */
    protected function totals(AdminCommissionsFilterRequest $request): array
    {
        $rows = (new AdminCommissionsFilter($request))
            ->apply(AffiliateCommission::query())
            ->selectRaw('currency, status, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('currency', 'status')
            ->get();

        return $rows->map(fn ($row): array => [
            'currency' => (string) $row->currency,
            'status' => (string) $row->status,
            'total' => (int) $row->total,
            'count' => (int) $row->count,
        ])->all();
    }

    public function approve(PayoutActionRequest $request): RedirectResponse
    {
        abort_unless($this->policy->manage($request->user()), 403);

        $count = AffiliateCommission::query()
            ->whereIn('id', $request->ids())
            ->where('status', AffiliateCommission::STATUS_PENDING)
            ->update(['status' => AffiliateCommission::STATUS_APPROVED, 'approved_at' => now()]);

        return back()->with('success', __('larafoundry::billing.admin.payout.approved', ['count' => $count]));
    }

    public function markPaid(PayoutActionRequest $request): RedirectResponse
    {
        abort_unless($this->policy->manage($request->user()), 403);

        $reference = $request->validated()['reference'] ?? null;

        $count = AffiliateCommission::query()
            ->whereIn('id', $request->ids())
            ->where('status', AffiliateCommission::STATUS_APPROVED)
            ->update(['status' => AffiliateCommission::STATUS_PAID, 'paid_at' => now(), 'payout_reference' => $reference]);

        return back()->with('success', __('larafoundry::billing.admin.payout.paid', ['count' => $count]));
    }

    public function void(PayoutActionRequest $request): RedirectResponse
    {
        abort_unless($this->policy->manage($request->user()), 403);

        $count = AffiliateCommission::query()
            ->whereIn('id', $request->ids())
            ->whereIn('status', [AffiliateCommission::STATUS_PENDING, AffiliateCommission::STATUS_APPROVED])
            ->update(['status' => AffiliateCommission::STATUS_VOID]);

        return back()->with('success', __('larafoundry::billing.admin.payout.voided', ['count' => $count]));
    }

    /**
     * CSV of the current filtered report. PII-minimal: the partner's code/email, the
     * company id, money in minor units, status and the lifecycle timestamps — what an
     * operator needs to reconcile a manual payout, nothing more.
     */
    public function export(AdminCommissionsFilterRequest $request): StreamedResponse
    {
        abort_unless($this->policy->viewAny($request->user()), 403);

        $query = (new AdminCommissionsFilter($request))
            ->apply(AffiliateCommission::query()->with('affiliate.user'))
            ->orderByDesc('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'id', 'affiliate_code', 'affiliate_email', 'company_id', 'currency',
                'amount_minor', 'status', 'basis', 'created_at', 'approved_at', 'paid_at', 'payout_reference',
            ]);

            $query->chunk(500, function ($chunk) use ($out): void {
                foreach ($chunk as $commission) {
                    fputcsv($out, array_map([$this, 'csvCell'], [
                        $commission->id,
                        $commission->affiliate?->code,
                        $commission->affiliate?->user?->email,
                        $commission->company_id,
                        $commission->currency,
                        $commission->amount,
                        $commission->status,
                        $commission->basis,
                        $commission->created_at?->format('Y-m-d H:i:s'),
                        $commission->approved_at?->format('Y-m-d H:i:s'),
                        $commission->paid_at?->format('Y-m-d H:i:s'),
                        $commission->payout_reference,
                    ]));
                }
            });

            fclose($out);
        }, 'affiliate-commissions.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="affiliate-commissions.csv"',
        ]);
    }

    /**
     * Neutralise spreadsheet formula injection: a cell whose text begins with =, +, -, @,
     * tab or CR is prefixed with an apostrophe so Excel/Sheets treats it as literal text,
     * not a formula. Cheap defence on the operator-authored payout_reference et al.
     */
    protected function csvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    protected function perPage(): int
    {
        return (int) config('larafoundry-billing.affiliate.console.per_page', 25);
    }

    /**
     * Affiliates for the report's filter dropdown.
     *
     * @return list<array{id: int, code: string, email: ?string}>
     */
    protected function availableAffiliates(): array
    {
        return Affiliate::query()->with('user')->orderBy('code')->limit(200)->get()
            ->map(fn (Affiliate $affiliate): array => [
                'id' => (int) $affiliate->getKey(),
                'code' => $affiliate->code,
                'email' => is_object($affiliate->user) ? ($affiliate->user->email ?? null) : null,
            ])->all();
    }
}
