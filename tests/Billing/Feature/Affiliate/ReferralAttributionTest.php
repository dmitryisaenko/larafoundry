<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Listeners\AttributeReferral;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Referral attribution (Ф4.4, sub-step B): the public capture route drops a cookie, and
 * the AttributeReferral listener on the core's CompanyCreated event locks a referral —
 * fail-closed on self-referral, an unknown/inactive code, an already-attributed company,
 * or no cookie.
 */
function refUser(string $slug): PaymentUser
{
    $id = DB::table('users')->insertGetId([
        'name' => 'User',
        'email' => "u+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return PaymentUser::query()->findOrFail($id);
}

function refCompany(string $slug, int $ownerId): BillableCompany
{
    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme '.$slug,
        'slug' => $slug,
        'country' => 'US',
        'created_by_id' => $ownerId,
    ])->save();

    return $company->refresh();
}

function runAttribution(?string $code, BillableCompany $company, PaymentUser $owner): void
{
    $request = Request::create('/register', 'POST');

    if ($code !== null) {
        $request->cookies->set((string) config('larafoundry-billing.affiliate.attribution.cookie', 'lf_ref'), $code);
    }

    (new AttributeReferral($request))->handle(new CompanyCreated($company, $owner));
}

it('drops an attribution cookie for an active code and redirects to landing', function () {
    config()->set('larafoundry-billing.affiliate.attribution.landing', '/welcome');
    $affiliate = Affiliate::ensureFor(refUser('cap-a')->getKey());

    $this->get('/r/'.$affiliate->code)
        ->assertRedirect('/welcome')
        ->assertCookie('lf_ref');
});

it('sets no cookie for an unknown or inactive code (no enumeration oracle)', function () {
    $disabled = Affiliate::ensureFor(refUser('cap-dis')->getKey());
    $disabled->update(['status' => Affiliate::STATUS_DISABLED]);

    $this->get('/r/NOPE9999')->assertRedirect()->assertCookieMissing('lf_ref');
    $this->get('/r/'.$disabled->code)->assertRedirect()->assertCookieMissing('lf_ref');
});

it('locks a referral on company creation, stamping a recurring window', function () {
    config()->set('larafoundry-billing.affiliate.commission.trigger', 'recurring');
    config()->set('larafoundry-billing.affiliate.commission.window_months', 12);

    $partner = refUser('lock-p');
    $affiliate = Affiliate::ensureFor($partner->getKey());
    $owner = refUser('lock-o');
    $company = refCompany('locked', $owner->getKey());

    runAttribution($affiliate->code, $company, $owner);

    $referral = Referral::query()->where('referred_company_id', $company->getKey())->first();

    expect($referral)->not->toBeNull()
        ->and($referral->affiliate_id)->toBe($affiliate->getKey())
        ->and($referral->source_code)->toBe($affiliate->code)
        ->and($referral->window_ends_at)->not->toBeNull()
        ->and($referral->window_ends_at->greaterThan(now()->addMonths(11)))->toBeTrue();
});

it('leaves the window open for the first_payment / lifetime triggers', function () {
    config()->set('larafoundry-billing.affiliate.commission.trigger', 'first_payment');

    $affiliate = Affiliate::ensureFor(refUser('open-p')->getKey());
    $owner = refUser('open-o');
    $company = refCompany('open', $owner->getKey());

    runAttribution($affiliate->code, $company, $owner);

    expect(Referral::query()->where('referred_company_id', $company->getKey())->value('window_ends_at'))->toBeNull();
});

it('refuses to credit a partner for a company they own (self-referral)', function () {
    $partner = refUser('self');
    $affiliate = Affiliate::ensureFor($partner->getKey());
    $company = refCompany('self-co', $partner->getKey()); // owner IS the partner

    runAttribution($affiliate->code, $company, $partner);

    expect(Referral::query()->where('referred_company_id', $company->getKey())->exists())->toBeFalse();
});

it('never re-points an already attributed company (lock-once)', function () {
    $first = Affiliate::ensureFor(refUser('first')->getKey());
    $second = Affiliate::ensureFor(refUser('second')->getKey());
    $owner = refUser('lockonce-o');
    $company = refCompany('lockonce', $owner->getKey());

    runAttribution($first->code, $company, $owner);
    runAttribution($second->code, $company, $owner); // second click, same company

    $referral = Referral::query()->where('referred_company_id', $company->getKey())->first();

    expect(Referral::query()->where('referred_company_id', $company->getKey())->count())->toBe(1)
        ->and($referral->affiliate_id)->toBe($first->getKey());
});

it('does not attribute for an inactive affiliate or a missing cookie', function () {
    $disabled = Affiliate::ensureFor(refUser('inact-p')->getKey());
    $disabled->update(['status' => Affiliate::STATUS_DISABLED]);
    $owner = refUser('inact-o');
    $company = refCompany('inactive', $owner->getKey());

    runAttribution($disabled->code, $company, $owner);
    expect(Referral::query()->where('referred_company_id', $company->getKey())->exists())->toBeFalse();

    $company2 = refCompany('nocookie', $owner->getKey());
    runAttribution(null, $company2, $owner);
    expect(Referral::query()->where('referred_company_id', $company2->getKey())->exists())->toBeFalse();
});

it('wires the attribution listener onto the core CompanyCreated event', function () {
    expect($this->app['events']->hasListeners(CompanyCreated::class))->toBeTrue();
});

it('ignores a registration attribution in teams mode', function () {
    // The personal-mode hook is registered on EVERY host with the program on. In teams
    // the tenant is the company, so a bare registration must attribute nothing —
    // otherwise a user would be attributed on sign-up and their company again later.
    $affiliate = Affiliate::ensureFor(refUser('teams-reg-p')->getKey());
    $newcomer = refUser('teams-reg-n');

    $request = Request::create('/register', 'POST');
    $request->cookies->set((string) config('larafoundry-billing.affiliate.attribution.cookie'), $affiliate->code);

    (new AttributeReferral($request))->handleRegistration(new Registered($newcomer));

    expect(Referral::query()->count())->toBe(0);
});
