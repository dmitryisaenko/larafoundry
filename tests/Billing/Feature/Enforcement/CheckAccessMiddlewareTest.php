<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The billing.access middleware: lets a paid company through, passes an unpaid one in
 * soft mode (frontend alerts) but redirects in hard mode (owner vs non-owner type),
 * hard-cascades an owner-ban in every mode, 403s JSON, never blocks an exempt route or
 * a company in setup, and stays open with billing off.
 */
function enforcementCompany(string $slug, array $companyAttrs = [], bool $ownerBlocked = false): BillableCompany
{
    $owner = PaymentUser::query()->create([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'user_blocked_at' => $ownerBlocked ? now() : null,
    ]);

    $company = new BillableCompany;
    $company->forceFill(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => $slug,
        'country' => 'US',
        'created_by_id' => $owner->getKey(),
    ], $companyAttrs))->save();

    return $company->refresh();
}

function actingFor(BillableCompany $company, bool $asOwner = true): PaymentUser
{
    $user = $asOwner
        ? PaymentUser::query()->findOrFail($company->created_by_id)
        : PaymentUser::query()->create([
            'name' => 'Member',
            'email' => 'member+'.$company->slug.'@example.test',
            'password' => bcrypt('secret'),
        ]);

    $user->activeCompany = $company;

    return $user;
}

beforeEach(function () {
    Route::middleware('billing.access')->get('/app/work', fn () => response('ok'))->name('app.work');
    Route::get('/billing/blocked', fn () => response('blocked'))->name('billing.blocked');
    Route::middleware('billing.access')->get('/billing/pricing', fn () => response('pricing'))->name('billing.pricing');
});

it('lets a company with a live subscription through', function () {
    $company = enforcementCompany('mw-live', ['subscription_ends_at' => Carbon::now()->addMonth()]);

    $this->actingAs(actingFor($company))->get('/app/work')->assertOk();
});

it('passes through an unpaid company in soft mode (default)', function () {
    $company = enforcementCompany('mw-soft');

    $this->actingAs(actingFor($company))->get('/app/work')->assertOk();
});

it('redirects an unpaid owner to the blocked page in hard mode', function () {
    config(['larafoundry-billing.enforcement.mode' => 'hard']);
    $company = enforcementCompany('mw-hard-owner');

    $this->actingAs(actingFor($company, asOwner: true))
        ->get('/app/work')
        ->assertRedirect(route('billing.blocked', ['type' => 'payment_required']));
});

it('redirects a non-owner with the non_owner type in hard mode', function () {
    config(['larafoundry-billing.enforcement.mode' => 'hard']);
    $company = enforcementCompany('mw-hard-member');

    $this->actingAs(actingFor($company, asOwner: false))
        ->get('/app/work')
        ->assertRedirect(route('billing.blocked', ['type' => 'payment_required_non_owner']));
});

it('hard-blocks an owner-banned company even in soft mode (cascade)', function () {
    config(['larafoundry-billing.enforcement.mode' => 'soft']);
    $company = enforcementCompany('mw-ownerban', ['subscription_ends_at' => Carbon::now()->addMonth()], ownerBlocked: true);

    $this->actingAs(actingFor($company, asOwner: false))
        ->get('/app/work')
        ->assertRedirect(route('billing.blocked', ['type' => 'owner_banned']));
});

it('returns 403 for a JSON request in hard mode', function () {
    config(['larafoundry-billing.enforcement.mode' => 'hard']);
    $company = enforcementCompany('mw-json');

    $this->actingAs(actingFor($company))->getJson('/app/work')->assertStatus(403);
});

it('redirects an Inertia XHR navigation via X-Inertia-Location, not a 403 (cron-polish)', function () {
    config(['larafoundry-billing.enforcement.mode' => 'hard']);
    $company = enforcementCompany('mw-xhr');

    $this->actingAs(actingFor($company))
        ->get('/app/work', ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('billing.blocked', ['type' => 'payment_required']));
});

it('never blocks an exempt route (the pricing page)', function () {
    config(['larafoundry-billing.enforcement.mode' => 'hard']);
    $company = enforcementCompany('mw-exempt');

    $this->actingAs(actingFor($company))->get('/billing/pricing')->assertOk();
});

it('does not gate a company still in setup', function () {
    config(['larafoundry-billing.enforcement.mode' => 'hard']);
    $company = enforcementCompany('mw-setup', ['country' => null]);

    $this->actingAs(actingFor($company))->get('/app/work')->assertOk();
});

it('stays open when billing is disabled', function () {
    config(['larafoundry.billing.enabled' => false, 'larafoundry-billing.enforcement.mode' => 'hard']);
    $company = enforcementCompany('mw-off');

    $this->actingAs(actingFor($company))->get('/app/work')->assertOk();
});
