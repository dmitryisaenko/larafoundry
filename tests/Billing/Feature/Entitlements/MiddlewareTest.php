<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\EntitledUser;
use Illuminate\Support\Facades\Route;

/**
 * The `entitlement:<feature>` route middleware: lets an entitled tenant through,
 * refuses an unentitled one (JSON 403 / web redirect-or-403), stays open when billing
 * is off, and never lets a super-admin bypass it (decision D-B6-1). The active company
 * is held in-memory on the stub user, so the tests need no database.
 */
function userOnPlan(?string $planId, bool $superAdmin = false): EntitledUser
{
    $user = new EntitledUser;

    if ($planId !== null) {
        $user->activeCompany = (new BillableCompany)->forceFill(['plan_id' => $planId]);
    }

    $user->superAdmin = $superAdmin;

    return $user;
}

function gatedRoute(string $feature = 'production.module'): void
{
    Route::middleware('entitlement:'.$feature)->get('/test/gated', fn () => response('ok'));
}

it('lets a tenant whose plan includes the feature through', function () {
    gatedRoute();

    $this->actingAs(userOnPlan('business'))
        ->get('/test/gated')
        ->assertOk()
        ->assertSee('ok');
});

it('refuses a JSON request whose plan lacks the feature with a 403', function () {
    gatedRoute();

    $this->actingAs(userOnPlan('free'))
        ->getJson('/test/gated')
        ->assertStatus(403);
});

it('refuses a web request with a 403 when no upgrade route is configured', function () {
    gatedRoute();

    $this->actingAs(userOnPlan('free'))
        ->get('/test/gated')
        ->assertStatus(403);
});

it('redirects a web request to the configured upgrade route', function () {
    Route::get('/billing/upgrade', fn () => response('upgrade'))->name('billing.upgrade');
    config(['larafoundry-billing.entitlements.redirect_route' => 'billing.upgrade']);
    gatedRoute();

    $this->actingAs(userOnPlan('free'))
        ->get('/test/gated')
        ->assertRedirect(route('billing.upgrade'));
});

it('stays open when billing is disabled regardless of plan', function () {
    config(['larafoundry.billing.enabled' => false]);
    gatedRoute();

    $this->actingAs(userOnPlan('free'))
        ->get('/test/gated')
        ->assertOk();
});

it('still gates a super-admin (no Gate::before bypass)', function () {
    gatedRoute();

    $this->actingAs(userOnPlan('free', superAdmin: true))
        ->getJson('/test/gated')
        ->assertStatus(403);
});

it('fails closed when there is no active company', function () {
    gatedRoute();

    $this->actingAs(userOnPlan(null))
        ->getJson('/test/gated')
        ->assertStatus(403);
});
