<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The partner's own affiliate dashboard (Ф4.4): their referral code/link, referral count
 * and per-currency earnings by status.
 *
 * Strictly self-scoped — everything is resolved through the authenticated user's OWN
 * affiliate row (by user_id), so a partner can never see another's referrals or
 * commissions. In `auto` eligibility the row is created lazily on first view; in
 * `self_serve` a not-yet-enrolled user is shown an opt-in; in `admin` they see a neutral
 * "not enrolled" state (a super-admin enrols them).
 */
final class AffiliateDashboardController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $eligibility = (string) config('larafoundry-billing.affiliate.eligibility', 'auto');
        $affiliate = Affiliate::query()->where('user_id', $user->getAuthIdentifier())->first();

        if ($affiliate === null && $eligibility === 'auto') {
            $affiliate = Affiliate::ensureFor((int) $user->getAuthIdentifier());
        }

        if ($affiliate === null) {
            return Inertia::render('Affiliate/Dashboard', [
                'enrolled' => false,
                'eligibility' => $eligibility,
                'can_opt_in' => $eligibility === 'self_serve',
            ]);
        }

        return Inertia::render('Affiliate/Dashboard', [
            'enrolled' => true,
            'eligibility' => $eligibility,
            'awaiting_approval' => $affiliate->status === Affiliate::STATUS_PENDING,
            'active' => $affiliate->isActive(),
            'affiliate' => [
                'code' => $affiliate->code,
                'status' => $affiliate->status,
                'link' => $this->referralLink($affiliate->code),
            ],
            'referrals_count' => $affiliate->referrals()->count(),
            'earnings' => $this->earnings($affiliate),
        ]);
    }

    /**
     * Opt in to the program (self_serve eligibility only). Pending when the program
     * requires approval, else active immediately.
     */
    public function optIn(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);
        abort_unless(config('larafoundry-billing.affiliate.eligibility') === 'self_serve', 403);

        $status = config('larafoundry-billing.affiliate.require_approval')
            ? Affiliate::STATUS_PENDING
            : Affiliate::STATUS_ACTIVE;

        Affiliate::ensureFor((int) $user->getAuthIdentifier(), $status);

        return back();
    }

    /**
     * Per-currency earnings broken down by status for THIS partner only.
     *
     * @return list<array{currency: string, status: string, total: int, count: int}>
     */
    private function earnings(Affiliate $affiliate): array
    {
        return $affiliate->commissions()
            ->selectRaw('currency, status, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('currency', 'status')
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) $row->currency,
                'status' => (string) $row->status,
                'total' => (int) $row->total,
                'count' => (int) $row->count,
            ])->all();
    }

    private function referralLink(string $code): string
    {
        $prefix = trim((string) config('larafoundry-billing.affiliate.attribution.capture_route.prefix', 'r'), '/');

        return url($prefix === '' ? "/{$code}" : "/{$prefix}/{$code}");
    }
}
