<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Company::hasAccess() is the billing gate (phase 3.1) — the method RBAC and
 * middleware call. These tests pin its two regimes: OPEN when billing is
 * disabled (the FREE promise — the core is fully usable with no paywall), and
 * FAIL-CLOSED when enabled (only a live trial or active subscription grants
 * access). Getting this wrong means either everyone is locked out or the paywall
 * is bypassable, so both branches are nailed down.
 */

/**
 * Create a company, setting any billing columns the way the add-on would —
 * server-side via forceFill, because they are deliberately unfillable. Passing
 * them through create() would silently drop them (which a dedicated test below
 * asserts).
 */
function billingCompany(array $billing = []): Company
{
    $owner = User::create([
        'name' => 'Owner',
        'email' => 'owner'.uniqid().'@x.test',
        'password' => 'secret-pass',
    ]);

    $company = Company::create([
        'name' => 'Acme',
        'slug' => 'acme-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);

    if ($billing !== []) {
        $company->forceFill($billing)->save();
    }

    return $company;
}

it('grants access when billing is disabled regardless of subscription state', function () {
    config()->set('larafoundry.billing.enabled', false);

    // No trial, no subscription, no plan — would be denied if billing were on.
    $company = billingCompany();

    expect($company->hasAccess())->toBeTrue();
});

it('grants access on an active trial when billing is enabled', function () {
    config()->set('larafoundry.billing.enabled', true);

    $company = billingCompany(['trial_ends_at' => now()->addDays(5)]);

    expect($company->hasAccess())->toBeTrue();
});

it('grants access on an active subscription when billing is enabled', function () {
    config()->set('larafoundry.billing.enabled', true);

    $company = billingCompany(['subscription_ends_at' => now()->addMonth()]);

    expect($company->hasAccess())->toBeTrue();
});

it('denies access when billing is enabled and there is no trial or subscription', function () {
    config()->set('larafoundry.billing.enabled', true);

    $company = billingCompany();

    expect($company->hasAccess())->toBeFalse();
});

it('denies access when billing is enabled and trial and subscription are both expired', function () {
    config()->set('larafoundry.billing.enabled', true);

    $company = billingCompany([
        'trial_ends_at' => now()->subDay(),
        'subscription_ends_at' => now()->subDay(),
    ]);

    expect($company->hasAccess())->toBeFalse();
});

it('exposes the same state through the HasSubscription accessors', function () {
    $company = billingCompany([
        'trial_ends_at' => now()->addDays(3),
        'subscription_ends_at' => now()->subDay(),
    ]);

    expect($company->isOnTrial())->toBeTrue()
        ->and($company->hasActiveSubscription())->toBeFalse();
});

it('does not allow the billing columns to be mass-assigned', function () {
    // Subscription state is written server-side by the add-on, never from user
    // input — the columns are deliberately absent from $fillable. Go through
    // create() directly (not the forceFill helper) to exercise mass assignment.
    $owner = User::create([
        'name' => 'Owner',
        'email' => 'owner'.uniqid().'@x.test',
        'password' => 'secret-pass',
    ]);

    $company = Company::create([
        'name' => 'Acme',
        'slug' => 'acme-'.uniqid(),
        'created_by_id' => $owner->id,
        'subscription_ends_at' => now()->addYear(),
    ]);

    config()->set('larafoundry.billing.enabled', true);

    // The mass-assigned value was dropped, so a would-be self-granted year of
    // access never took hold.
    expect($company->refresh()->subscription_ends_at)->toBeNull()
        ->and($company->hasAccess())->toBeFalse();
});
