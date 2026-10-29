<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Actions;

use Dmitryisaenko\LaraFoundry\Billing\Events\TrialStarted;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\FreeMonthUnavailableException;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCodeRedemption;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Grant the paying subject an app-side free month / trial (sub-phase B5, decision D-B5-2).
 *
 * The donor's "Free Month" offer (ServicePaymentController:109-153) ported to the
 * add-on. Unlike a paid subscription it touches NO gateway and needs NO card — it
 * just stamps the core's trial columns, so it is a genuine "no credit card" trial
 * that works identically whether the host bills via Stripe or Paddle.
 *
 * Two things changed from the donor:
 *
 *  - Eligibility is PER-SUBJECT (D-B5-3): the gate is the payer's own
 *    `free_month_used_at IS NULL`, not the donor's per-user `users.has_used_free_month`
 *    (the core has no such user column and B5 does not touch core schema).
 *  - The write is `forceFill()` because the core deliberately keeps the billing
 *    columns out of `$fillable` (server-only state). The whole thing runs inside a
 *    transaction and RE-CHECKS eligibility after locking the row, so two concurrent
 *    requests cannot both grant a free month.
 *
 * Monthly-only, like the donor (a free month against a yearly plan makes no sense).
 *
 * The subject is typed to Eloquent's Model, not {@see Company}: in `teams` mode it is
 * the host's Company, in `personal` mode the user paying for itself. Only Model-level
 * behaviour is used (lock, getAttribute, forceFill), so both work unchanged.
 */
final class ActivateFreeMonthAction
{
    /**
     * @param  string  $period  the billing period the trial is granted against
     * @param  PromoCode|null  $code  the promo code that triggered the grant, if any
     * @param  int|null  $userId  the authenticated redeemer (for the audit row), if any
     *
     * @throws FreeMonthUnavailableException when the offer is off, the period is not
     *                                       monthly, or the company already used it
     */
    public function execute(
        Model $company,
        string $planId,
        string $period = 'monthly',
        ?PromoCode $code = null,
        ?int $userId = null,
    ): Model {
        if (! $this->offerEnabled()) {
            throw new FreeMonthUnavailableException('The free-month offer is not enabled.');
        }

        if ($period !== $this->offerPeriod()) {
            throw new FreeMonthUnavailableException('The free month is available for monthly billing only.');
        }

        return DB::transaction(function () use ($company, $planId, $period, $code, $userId): Model {
            // When a promo code drives the grant, re-validate it UNDER A LOCK before
            // doing anything — same discipline as RedeemPromoCodeAction. Without this
            // an expired / inactive / exhausted / discount-kind / another-user's
            // personal code would still be recorded and have its usage bumped. The
            // caller's $code instance is only an id carrier; the locked row is the
            // source of truth.
            $lockedCode = null;

            if ($code !== null) {
                $lockedCode = PromoCode::query()
                    ->lockForUpdate()
                    ->whereKey($code->getKey())
                    ->first();

                if ($lockedCode === null
                    || ! $lockedCode->isFreeMonth()
                    || ! $lockedCode->canBeUsed($userId, BillingSubject::key($company))) {
                    throw new FreeMonthUnavailableException('This promo code cannot be redeemed for a free month.');
                }
            }

            // Lock the company row and re-read the eligibility column inside the
            // transaction so a concurrent activation cannot double-grant.
            $locked = $company->newQuery()
                ->lockForUpdate()
                ->whereKey($company->getKey())
                ->first();

            if ($locked === null) {
                throw new FreeMonthUnavailableException('Company not found.');
            }

            if ($locked->getAttribute('free_month_used_at') !== null) {
                throw new FreeMonthUnavailableException('This subject has already used its free month.');
            }

            $now = Carbon::now();

            // Billing columns are not $fillable on the core Company (server-only) —
            // write them through forceFill, exactly as the webhook mirror does.
            $locked->forceFill([
                'plan_id' => $planId,
                'billing_period' => $period,
                'trial_ends_at' => $now->copy()->addDays($this->trialLengthDays()),
                'free_month_used_at' => $now,
            ])->save();

            // Re-decode the memoised state so an immediate isOnTrial() on this
            // instance reflects the freshly written columns (mirror idiom).
            if (method_exists($locked, 'forgetSubscriptionState')) {
                $locked->forgetSubscriptionState();
            }

            if ($lockedCode !== null) {
                PromoCodeRedemption::create([
                    'promo_code_id' => $lockedCode->getKey(),
                    // The TENANT key: a company id in `teams`, a user id in `personal`.
                    'company_id' => BillingSubject::key($locked),
                    'user_id' => $userId,
                    'kind' => PromoCode::KIND_FREE_MONTH,
                    'redeemed_at' => $now,
                ]);

                $lockedCode->incrementUsage();
            }

            TrialStarted::dispatch(BillingSubject::tenant($locked), $lockedCode?->code);

            return $locked;
        });
    }

    private function offerEnabled(): bool
    {
        return (bool) config('larafoundry-billing.trial.free_month.enabled', false);
    }

    private function offerPeriod(): string
    {
        $period = config('larafoundry-billing.trial.free_month.period', 'monthly');

        return is_string($period) && $period !== '' ? $period : 'monthly';
    }

    private function trialLengthDays(): int
    {
        $days = (int) config('larafoundry-billing.trial.free_month.length_days', 30);

        return $days > 0 ? $days : 30;
    }
}
