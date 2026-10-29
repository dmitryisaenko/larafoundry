<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\BillingServiceProvider;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The partner dashboard (Ф4.4, sub-step E): self-scoped code/link/earnings, lazy
 * enrolment in auto eligibility, opt-in in self_serve, a neutral state in admin, and the
 * auth guard.
 */
function billingDashUser(string $slug): PaymentUser
{
    return PaymentUser::query()->create([
        'name' => 'U', 'email' => "dash+{$slug}@example.test", 'password' => bcrypt('secret'),
    ]);
}

function dashCommission(Affiliate $affiliate, int $companyId, string $status, int $amount, string $currency = 'USD'): void
{
    $referral = Referral::query()->firstOrCreate(
        ['referred_company_id' => $companyId],
        ['affiliate_id' => $affiliate->getKey(), 'source_code' => $affiliate->code, 'attributed_at' => now()],
    );

    $payment = CompanyPayment::query()->create([
        'company_id' => $companyId, 'plan_id' => 'pro', 'billing_period' => 'monthly',
        'amount' => 2000, 'currency' => $currency, 'payment_status' => 'success', 'paid_at' => now(),
    ]);

    AffiliateCommission::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referral_id' => $referral->getKey(),
        'company_payment_id' => $payment->getKey(),
        'company_id' => $companyId,
        'currency' => $currency,
        'amount' => $amount,
        'status' => $status,
        'basis' => 'per_plan',
    ]);
}

it('lazily enrols and shows the dashboard in auto eligibility', function () {
    config()->set('larafoundry-billing.affiliate.eligibility', 'auto');
    $user = billingDashUser('auto');

    $this->actingAs($user)
        ->get('/affiliate', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Affiliate/Dashboard')
        ->assertJsonPath('props.enrolled', true);

    expect(Affiliate::query()->where('user_id', $user->getKey())->exists())->toBeTrue();
});

it('shows only the signed-in partner own earnings', function () {
    config()->set('larafoundry-billing.affiliate.eligibility', 'auto');
    $user = billingDashUser('own');
    $mine = Affiliate::ensureFor($user->getKey());
    dashCommission($mine, 11000, AffiliateCommission::STATUS_PENDING, 500);

    $other = Affiliate::ensureFor(billingDashUser('other')->getKey());
    dashCommission($other, 11001, AffiliateCommission::STATUS_PENDING, 900);

    $earnings = collect($this->actingAs($user)
        ->get('/affiliate', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.earnings'));

    expect($earnings)->toHaveCount(1)
        ->and($earnings->first()['total'])->toBe(500); // never the other partner's 900
});

it('offers opt-in in self_serve and enrols on opt-in', function () {
    config()->set('larafoundry-billing.affiliate.eligibility', 'self_serve');
    config()->set('larafoundry-billing.affiliate.require_approval', false);
    $user = billingDashUser('ss');

    $this->actingAs($user)
        ->get('/affiliate', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.enrolled', false)
        ->assertJsonPath('props.can_opt_in', true);

    $this->actingAs($user)->post('/affiliate/opt-in')->assertStatus(302);

    expect(Affiliate::query()->where('user_id', $user->getKey())->value('status'))->toBe(Affiliate::STATUS_ACTIVE);
});

it('enrols pending when self_serve requires approval', function () {
    config()->set('larafoundry-billing.affiliate.eligibility', 'self_serve');
    config()->set('larafoundry-billing.affiliate.require_approval', true);
    $user = billingDashUser('ss-appr');

    $this->actingAs($user)->post('/affiliate/opt-in')->assertStatus(302);

    expect(Affiliate::query()->where('user_id', $user->getKey())->value('status'))->toBe(Affiliate::STATUS_PENDING);
});

it('shows a neutral not-enrolled state and refuses opt-in in admin eligibility', function () {
    config()->set('larafoundry-billing.affiliate.eligibility', 'admin');
    $user = billingDashUser('admin-el');

    $this->actingAs($user)
        ->get('/affiliate', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.enrolled', false)
        ->assertJsonPath('props.can_opt_in', false);

    expect(Affiliate::query()->where('user_id', $user->getKey())->exists())->toBeFalse();

    $this->actingAs($user)->post('/affiliate/opt-in')->assertStatus(403);
});

it('requires authentication', function () {
    $this->getJson('/affiliate')->assertStatus(401);
});

it('publishes the partner dashboard page under its own tag', function () {
    $paths = BillingServiceProvider::pathsToPublish(
        BillingServiceProvider::class,
        'larafoundry-billing-affiliate-pages',
    );

    expect($paths)->not->toBeEmpty();

    $dir = dirname(__DIR__, 4).'/resources/js/Pages/Affiliate';
    expect(file_exists("{$dir}/Dashboard.vue"))->toBeTrue();
});
