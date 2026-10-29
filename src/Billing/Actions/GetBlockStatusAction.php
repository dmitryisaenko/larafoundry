<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Actions;

use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingSubscriber;
use Dmitryisaenko\LaraFoundry\Billing\Http\Middleware\CheckAccessMiddleware;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Illuminate\Support\Carbon;

/**
 * Resolves the paying subject's billing block status (sub-phase B7a), ported from the
 * donor's GetBlockStatusAction and trimmed to the add-on's axis.
 *
 * Duck-typed on purpose: the subject is a Company in `teams` mode and the user itself in
 * `personal`, and every probe below (`isInSetup`, `createdBy`, `hasAccess`) is optional —
 * a subject that does not carry one simply skips that reason.
 *
 * It reports SUBJECT / subscription state only — the current user's OWN ban is the
 * core's concern (EnsureAccountIsActive), so it is not duplicated here. The result
 * feeds two consumers: the {@see CheckAccessMiddleware}
 * decision, and the shared Inertia props a host exposes so the frontend can show a soft
 * alert banner.
 *
 * Reasons: `owner_banned` (the company's founder/payer is blocked → cascade),
 * `payment_expired` (no access and a prior payment exists), `first_payment_required`
 * (no access and never paid).
 */
final class GetBlockStatusAction
{
    /**
     * @return array{company_blocked: bool, company_block_reason: ?string, subscription_expires_soon: bool, days_until_expiry: ?int}
     */
    public function __invoke(?object $company = null): array
    {
        $status = [
            'company_blocked' => false,
            'company_block_reason' => null,
            'subscription_expires_soon' => false,
            'days_until_expiry' => null,
        ];

        if (! is_object($company)) {
            return $status;
        }

        // A company still finishing setup has not been asked to pay yet.
        if (method_exists($company, 'isInSetup') && $company->isInSetup()) {
            return $status;
        }

        // The owner (payer) is banned → the whole company is frozen for every member.
        $owner = method_exists($company, 'createdBy') ? $company->createdBy : null;

        if (is_object($owner) && method_exists($owner, 'isBlocked') && $owner->isBlocked()) {
            $status['company_blocked'] = true;
            $status['company_block_reason'] = 'owner_banned';

            return $status;
        }

        // No live subscription / trial → blocked; distinguish first-time vs expired.
        //
        // A subject that cannot ANSWER the question counts as having access only while
        // billing is off. With billing on, silence is not consent: in `personal` mode it
        // means the host pointed `billable_model` at a User without the
        // {@see BillingSubscriber} trait, and reading that as "access granted" would be a
        // paywall that never charges anyone — invisible, because checkout, webhooks and
        // the console all keep working. Fail closed instead, so the misconfiguration
        // shows up as "payment required" on the first request. (In `teams` the subject is
        // always a core Company, which always carries hasAccess(), so nothing changes.)
        $billingOn = (bool) config('larafoundry.billing.enabled', false);

        $hasAccess = method_exists($company, 'hasAccess')
            ? (bool) $company->hasAccess()
            : ! $billingOn;

        if (! $hasAccess) {
            // Query the ledger directly rather than $company->payments() — that relation
            // is registered dynamically (resolveRelationUsing), so method_exists() on it
            // is false even in production; a direct query is robust.
            $hasPayments = CompanyPayment::query()
                // The tenant key: a company id in `teams`, a user id in `personal`.
                ->where('company_id', BillingSubject::key($company))
                ->exists();

            $status['company_blocked'] = true;
            $status['company_block_reason'] = $hasPayments ? 'payment_expired' : 'first_payment_required';

            return $status;
        }

        // Access is live: warn when the subscription end is within the window. Guarded on
        // expiry being in the future so a trial-covered company with an old
        // subscription_ends_at is not mis-flagged (works across Carbon versions).
        $endsAt = $company->subscription_ends_at ?? null;

        if ($endsAt !== null) {
            $now = Carbon::now()->startOfDay();
            $expiry = Carbon::parse($endsAt)->startOfDay();

            if ($expiry->greaterThanOrEqualTo($now)) {
                $days = (int) $now->diffInDays($expiry);
                $window = (int) config('larafoundry-billing.enforcement.expiring_within_days', 10);

                if ($days <= $window) {
                    $status['subscription_expires_soon'] = true;
                    $status['days_until_expiry'] = $days;
                }
            }
        }

        return $status;
    }
}
