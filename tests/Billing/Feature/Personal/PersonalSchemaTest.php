<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\BillableTable;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Schema half of personal-mode support: the add-on's migrations follow the CONFIGURED
 * billable, not the literal `companies`. With `billable_model` pointing at the User,
 * Cashier's identity columns, `billing_period` AND the core's subscription-state columns
 * all land on `users` — without them `Billable` on the user finds no `stripe_id` and the
 * access gate has nothing to read.
 */
it('puts the cashier identity columns on the billable table', function () {
    expect(Schema::hasColumn('users', 'stripe_id'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'pm_type'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'pm_last_four'))->toBeTrue();
});

it('puts the subscription-state columns on the billable table', function () {
    foreach (['billing_period', 'plan_id', 'trial_ends_at', 'subscription_ends_at', 'free_month_used_at'] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeTrue("users.{$column}");
    }
});

it('leaves the core companies columns exactly as the core created them', function () {
    // The core still owns `companies`; the add-on adds only its own Cashier columns
    // there in teams mode and nothing at all here.
    expect(Schema::hasColumn('companies', 'plan_id'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'stripe_id'))->toBeFalse();
});

it('resolves the billable table from config', function () {
    expect(BillableTable::for(BillableTable::STRIPE))->toBe('users')
        ->and(BillableTable::all())->toContain('users')
        ->and(BillableTable::core())->toBe('companies');

    config(['larafoundry-billing.billable_model' => 'App\\Models\\Nope']);

    // A mis-typed class must not fatal a migration — it simply resolves to nothing.
    expect(BillableTable::for(BillableTable::STRIPE))->toBeNull();

    config(['larafoundry-billing.billable_model' => BillableUser::class]);
});
