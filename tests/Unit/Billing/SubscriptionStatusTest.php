<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionStatus;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;

/*
 * The admin-facing subscription classifier (phase 3.3). It builds the richer
 * console vocabulary (on_trial / active / expiring / expired / never_activated)
 * on top of SubscriptionState's binary access answer. We drive it off an
 * unsaved Company instance with the billing columns set in memory — the
 * classifier reads attributes, no database needed.
 */

function statusCompany(array $attributes): Company
{
    return (new Company)->forceFill($attributes);
}

it('classifies a live trial as on_trial', function () {
    $company = statusCompany(['trial_ends_at' => now()->addDays(3)]);

    expect(SubscriptionStatus::for($company))->toBe('on_trial');
});

it('prefers on_trial even when a subscription is also active', function () {
    $company = statusCompany([
        'trial_ends_at' => now()->addDays(3),
        'subscription_ends_at' => now()->addMonth(),
    ]);

    expect(SubscriptionStatus::for($company))->toBe('on_trial');
});

it('classifies a far-future subscription as active', function () {
    $company = statusCompany(['subscription_ends_at' => now()->addMonths(2)]);

    expect(SubscriptionStatus::for($company))->toBe('active');
});

it('classifies a subscription ending within the window as expiring', function () {
    config(['larafoundry.admin.subscription_expiring_within_days' => 7]);
    $company = statusCompany(['subscription_ends_at' => now()->addDays(3)]);

    expect(SubscriptionStatus::for($company))->toBe('expiring');
});

it('classifies a past subscription end as expired', function () {
    $company = statusCompany(['subscription_ends_at' => now()->subDay()]);

    expect(SubscriptionStatus::for($company))->toBe('expired');
});

it('classifies a lapsed trial with no subscription as expired', function () {
    $company = statusCompany(['trial_ends_at' => now()->subDay()]);

    expect(SubscriptionStatus::for($company))->toBe('expired');
});

it('classifies a company with no billing dates as never_activated', function () {
    $company = statusCompany([]);

    expect(SubscriptionStatus::for($company))->toBe('never_activated');
});

it('respects a custom expiring window', function () {
    config(['larafoundry.admin.subscription_expiring_within_days' => 30]);
    $company = statusCompany(['subscription_ends_at' => now()->addDays(20)]);

    expect(SubscriptionStatus::for($company))->toBe('expiring');
});
