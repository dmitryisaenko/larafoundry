<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Controllers;

use DateTimeInterface;
use Dmitryisaenko\LaraFoundry\Billing\Actions\ActivateFreeMonthAction;
use Dmitryisaenko\LaraFoundry\Billing\Actions\GetBlockStatusAction;
use Dmitryisaenko\LaraFoundry\Billing\Actions\ValidatePromoCodeAction;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\FreeMonthUnavailableException;
use Dmitryisaenko\LaraFoundry\Billing\Http\Requests\ProcessPaymentRequest;
use Dmitryisaenko\LaraFoundry\Billing\Http\Resources\CompanyPaymentResource;
use Dmitryisaenko\LaraFoundry\Billing\Http\Resources\PlanResource;
use Dmitryisaenko\LaraFoundry\Billing\LaraFoundryBilling;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\RecordStripePayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

/**
 * The owner-facing billing screens (sub-phase B7a): pricing, checkout, history, and the
 * real payment flow.
 *
 * The donor's controller faked every payment as instantly successful. This does NOT:
 * `process()` hands a PAID plan to the gateway's hosted checkout ({@see subscribe})
 * and redirects the buyer there; the completed payment arrives back as a webhook
 * ({@see RecordStripePayment}) which writes
 * the ledger and mirrors access. The app-side free month (a genuine no-card trial) and
 * promo validation reuse the existing B5 actions rather than re-implementing them.
 *
 * Every screen works in BOTH tenancy modes: the paying subject is resolved through
 * {@see BillingSubject} — the active company in `teams`, the signed-in user in
 * `personal` (a plain B2C subscription, where `getActiveCompany()` is always null and
 * these screens used to 403 before reaching a gateway). Nothing below is typed to
 * Company any more; the subject is whatever `larafoundry-billing.billable_model`
 * points at, and the gateway/region seams receive it adapted to the core's Tenant
 * contract.
 */
final class ServicePaymentController
{
    public function __construct(private readonly GetBlockStatusAction $blockStatus) {}

    public function pricing(Request $request): InertiaResponse
    {
        $subject = $this->subject($request);
        $tenant = BillingSubject::tenant($subject);
        $currency = LaraFoundryBilling::region()->currencyFor($tenant);

        return Inertia::render('Billing/Pricing', [
            'plans' => $this->plans($request, $currency),
            'currency' => $currency,
            'company' => $this->subjectProps($subject),
            'blockStatus' => ($this->blockStatus)($subject),
            'canUseFreeMonth' => $this->canUseFreeMonth($subject),
            'gatewayConfigured' => LaraFoundryBilling::gateway($tenant)->isConfigured(),
        ]);
    }

    public function checkout(Request $request): InertiaResponse|RedirectResponse
    {
        $subject = $this->subject($request);

        $planId = (string) $request->query('plan', '');
        $period = (string) $request->query('period', 'monthly');
        $plan = app(PlanRepositoryContract::class)->find($planId);

        if ($plan === null || ! in_array($period, ['monthly', 'yearly'], true)) {
            return redirect()->route('billing.pricing');
        }

        $currency = LaraFoundryBilling::region()->currencyFor(BillingSubject::tenant($subject));

        return Inertia::render('Billing/Checkout', [
            'plan' => (new PlanResource($plan, $currency))->toArray($request),
            'period' => $period,
            'currency' => $currency,
            'company' => $this->subjectProps($subject),
            'canUseFreeMonth' => $this->canUseFreeMonth($subject),
            'promoEnabled' => (bool) config('larafoundry-billing.promo.enabled', false),
        ]);
    }

    public function history(Request $request): InertiaResponse
    {
        $subject = $this->subject($request);

        $payments = CompanyPayment::query()
            // `company_id` holds the TENANT key — a company id in `teams`, a user id
            // in `personal` (the column name predates personal-mode support).
            ->where('company_id', BillingSubject::key($subject))
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Billing/PaymentHistory', [
            'payments' => CompanyPaymentResource::collection($payments)->toArray($request),
            'company' => $this->subjectProps($subject),
        ]);
    }

    public function process(ProcessPaymentRequest $request): RedirectResponse
    {
        $subject = $this->subjectOrNull($request);

        if ($subject === null) {
            return back()->withErrors(['error' => __('larafoundry::billing.payment.no_company')]);
        }

        $planId = (string) $request->validated('plan_id');
        $period = (string) $request->validated('billing_period');
        $userId = $this->userId($request);

        // App-side free month via the offer flag — no gateway, no card.
        if ($request->boolean('use_free_month')) {
            return $this->activateFreeMonth($subject, $planId, $period, null, $userId);
        }

        // Validate a promo code if one was entered.
        $promo = null;

        if ($request->filled('promo_code')) {
            $result = app(ValidatePromoCodeAction::class)->execute(
                (string) $request->validated('promo_code'),
                $userId,
                BillingSubject::key($subject),
            );

            if (! $result->valid || $result->code === null) {
                return back()->withErrors(['promo_code' => __('larafoundry::billing.promo.invalid')]);
            }

            $promo = $result->code;

            // A free-month-kind promo code grants the app-side trial, not a gateway charge.
            if ($promo->isFreeMonth()) {
                return $this->activateFreeMonth($subject, $planId, $period, $promo, $userId);
            }
        }

        // Paid plan: hand off to the gateway's hosted checkout.
        $tenant = BillingSubject::tenant($subject);
        $gateway = LaraFoundryBilling::gateway($tenant);

        if (! $gateway->isConfigured()) {
            return back()->withErrors(['error' => __('larafoundry::billing.payment.not_connected')]);
        }

        $result = $gateway->subscribe($tenant, $planId, $period, $this->gatewayOptions($gateway, $promo));
        $url = $result['checkout_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return back()->withErrors(['error' => __('larafoundry::billing.payment.checkout_failed')]);
        }

        return redirect()->away($url);
    }

    private function activateFreeMonth(Model $subject, string $planId, string $period, ?PromoCode $code, ?int $userId): RedirectResponse
    {
        try {
            app(ActivateFreeMonthAction::class)->execute($subject, $planId, $period, $code, $userId);
        } catch (FreeMonthUnavailableException) {
            // Generic message: never reveal which eligibility check failed.
            return back()->withErrors(['use_free_month' => __('larafoundry::billing.trial.unavailable')]);
        }

        return redirect()->route('billing.history')
            ->with('success', __('larafoundry::billing.trial.activated'));
    }

    /**
     * Driver-agnostic subscribe options for a promo: hand the gateway the mapped
     * catalogue coupon for its own provider, else let the buyer enter one on the hosted
     * page. We never compute the discount (D-B5-1).
     *
     * @return array<string, mixed>
     */
    private function gatewayOptions(PaymentGatewayInterface $gateway, ?PromoCode $promo): array
    {
        if ($promo === null) {
            return [];
        }

        $coupon = $promo->couponIdFor($gateway->name());

        return $coupon !== null
            ? [$gateway->name().'_coupon' => $coupon]
            : ['promo' => true];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(Request $request, string $currency): array
    {
        return array_values(array_map(
            fn (PlanContract $plan): array => (new PlanResource($plan, $currency))->toArray($request),
            app(PlanRepositoryContract::class)->all(),
        ));
    }

    /**
     * The subject block the Vue pages render.
     *
     * Read through `getAttribute` rather than typed Company accessors so a User payer
     * (personal mode) serialises the same shape; `country` is simply absent there unless
     * the host keeps one. The prop key stays `company` — the published pages read it.
     *
     * @return array<string, mixed>
     */
    private function subjectProps(Model $subject): array
    {
        return [
            'name' => BillingSubject::label($subject),
            'country' => $subject->getAttribute('country'),
            'plan_id' => $subject->getAttribute('plan_id'),
            'billing_period' => $subject->getAttribute('billing_period'),
            'trial_ends_at' => $this->dateString($subject->getAttribute('trial_ends_at')),
            'subscription_ends_at' => $this->dateString($subject->getAttribute('subscription_ends_at')),
            // A person has no setup wizard to finish; a subject without the method is
            // simply "ready to be asked to pay" (same default the block status uses).
            'is_in_setup' => method_exists($subject, 'isInSetup') ? (bool) $subject->isInSetup() : false,
        ];
    }

    /**
     * Format a billing date column, whether the host casts it or leaves it a string.
     */
    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function canUseFreeMonth(Model $subject): bool
    {
        return (bool) config('larafoundry-billing.trial.free_month.enabled', false)
            && $subject->getAttribute('free_month_used_at') === null;
    }

    /**
     * The paying subject, or abort 403 for a GET page (these routes are meant to sit
     * behind the host's tenant middleware; this is the belt-and-suspenders guard).
     */
    private function subject(Request $request): Model
    {
        $subject = $this->subjectOrNull($request);

        abort_if($subject === null, 403);

        return $subject;
    }

    private function subjectOrNull(Request $request): ?Model
    {
        return BillingSubject::for($request->user());
    }

    private function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
