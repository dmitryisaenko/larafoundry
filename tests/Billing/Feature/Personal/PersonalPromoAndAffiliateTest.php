<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\AttributeReferral;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCodeRedemption;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableUser;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Promo codes and the partner program with a person as the tenant.
 *
 * Two things had to change for this to work at all: the redemption ledger keys off the
 * TENANT key (a user id here, not a company id), and referral attribution hangs off user
 * registration — in `personal` mode `CompanyCreated` never fires, so a partner link
 * could never earn anything.
 */
function promoUser(string $slug): BillableUser
{
    return BillableUser::query()->create([
        'name' => 'Person',
        'email' => "promo+{$slug}@example.test",
        'password' => bcrypt('secret'),
    ]);
}

function freeMonthCode(string $code = 'FREEMONTH'): PromoCode
{
    return PromoCode::query()->create([
        'code' => $code,
        'kind' => PromoCode::KIND_FREE_MONTH,
        'single_use_per_user' => true,
        'is_active' => true,
    ]);
}

it('records a free-month redemption against the user as tenant', function () {
    $user = promoUser('redeem');
    freeMonthCode();

    $this->actingAs($user)
        ->post('/billing/payment', [
            'plan_id' => 'pro',
            'billing_period' => 'monthly',
            'promo_code' => 'FREEMONTH',
        ])
        ->assertRedirect(route('billing.history'));

    $redemption = PromoCodeRedemption::query()->firstOrFail();

    expect((int) $redemption->company_id)->toBe($user->getKey())
        ->and((int) $redemption->user_id)->toBe($user->getKey())
        ->and($user->fresh()->trial_ends_at)->not->toBeNull();
});

it('refuses a second redemption of a single-use code by the same person', function () {
    $user = promoUser('twice');
    freeMonthCode();

    $this->actingAs($user)->post('/billing/payment', [
        'plan_id' => 'pro',
        'billing_period' => 'monthly',
        'promo_code' => 'FREEMONTH',
    ])->assertRedirect(route('billing.history'));

    $this->actingAs($user->fresh())->post('/billing/payment', [
        'plan_id' => 'pro',
        'billing_period' => 'monthly',
        'promo_code' => 'FREEMONTH',
    ])->assertSessionHasErrors('promo_code');

    expect(PromoCodeRedemption::query()->count())->toBe(1);
});

it('attributes a referral to the user who just registered', function () {
    $partner = promoUser('partner');
    $affiliate = Affiliate::ensureFor($partner->getKey());

    $newcomer = promoUser('newcomer');

    $request = Request::create('/register', 'POST');
    $request->cookies->set((string) config('larafoundry-billing.affiliate.attribution.cookie'), $affiliate->code);

    (new AttributeReferral($request))->handleRegistration(new Registered($newcomer));

    $referral = Referral::query()->firstOrFail();

    expect((int) $referral->referred_company_id)->toBe($newcomer->getKey())
        ->and((int) $referral->affiliate_id)->toBe($affiliate->getKey())
        ->and($referral->company?->getKey())->toBe($newcomer->getKey());
});

it('never credits a partner for their own registration', function () {
    $partner = promoUser('self');
    $affiliate = Affiliate::ensureFor($partner->getKey());

    $request = Request::create('/register', 'POST');
    $request->cookies->set((string) config('larafoundry-billing.affiliate.attribution.cookie'), $affiliate->code);

    (new AttributeReferral($request))->handleRegistration(new Registered($partner));

    expect(Referral::query()->count())->toBe(0);
});

it('ignores a CompanyCreated attribution in personal mode', function () {
    // Both hooks are registered on every host; the mode check lives with the behaviour,
    // so the teams path must be inert here or a tenant could be attributed twice.
    $partner = promoUser('teams-hook-partner');
    $owner = promoUser('teams-hook-owner');
    $affiliate = Affiliate::ensureFor($partner->getKey());

    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => 'acme-personal',
        'country' => 'US',
        'created_by_id' => $owner->getKey(),
    ])->save();

    $request = Request::create('/companies', 'POST');
    $request->cookies->set((string) config('larafoundry-billing.affiliate.attribution.cookie'), $affiliate->code);

    (new AttributeReferral($request))->handle(new CompanyCreated($company->refresh(), $owner));

    expect(Referral::query()->count())->toBe(0);
});

it('does not attribute a user created inside somebody else\'s request', function () {
    // An admin/invite/import flow also fires Registered — in the operator's request,
    // whose browser may carry a referral cookie. That must not credit a partner.
    $partner = promoUser('admin-created-partner');
    $affiliate = Affiliate::ensureFor($partner->getKey());
    $operator = promoUser('operator');
    $created = promoUser('admin-created');

    $this->actingAs($operator);

    $request = Request::create('/admin/users', 'POST');
    $request->cookies->set((string) config('larafoundry-billing.affiliate.attribution.cookie'), $affiliate->code);
    $request->setUserResolver(fn () => $operator);

    (new AttributeReferral($request))->handleRegistration(new Registered($created));

    expect(Referral::query()->count())->toBe(0);
});

it('accrues a commission from a referred person\'s payment', function () {
    $partner = promoUser('accrue-partner');
    $affiliate = Affiliate::ensureFor($partner->getKey());
    $referred = promoUser('accrue-referred');

    $request = Request::create('/register', 'POST');
    $request->cookies->set((string) config('larafoundry-billing.affiliate.attribution.cookie'), $affiliate->code);
    (new AttributeReferral($request))->handleRegistration(new Registered($referred));

    $payment = CompanyPayment::query()->create([
        'company_id' => $referred->getKey(),
        'user_id' => $referred->getKey(),
        'plan_id' => 'pro',
        'billing_period' => 'monthly',
        'amount' => 1900,
        'currency' => 'USD',
        'payment_status' => CompanyPayment::STATUS_SUCCESS,
        'payment_method' => 'stripe',
        'paid_at' => now(),
    ]);

    CompanyPaymentProcessed::dispatch($payment);

    $commission = AffiliateCommission::query()->firstOrFail();

    expect((int) $commission->company_id)->toBe($referred->getKey())
        ->and((int) $commission->affiliate_id)->toBe($affiliate->getKey())
        // per_plan basis, the shipped example: pro => USD 500 minor units.
        ->and($commission->amount)->toBe(500)
        ->and($commission->status)->toBe(AffiliateCommission::STATUS_PENDING);
});
