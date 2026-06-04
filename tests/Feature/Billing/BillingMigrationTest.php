<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * The billing migration adds five columns to `companies`, one of them
 * (plan_id) indexed. These pin that it applies, and that down()/up() round-trips
 * cleanly — the index is dropped before the column so a rollback never errors on
 * a driver that refuses to drop an indexed column.
 */

function billingMigration(): object
{
    return require __DIR__.'/../../../database/migrations/2026_01_05_001300_add_billing_columns_to_companies_table.php';
}

it('adds the billing columns to companies', function () {
    foreach (['plan_id', 'billing_period', 'trial_ends_at', 'subscription_ends_at', 'free_month_used_at'] as $column) {
        expect(Schema::hasColumn('companies', $column))->toBeTrue("missing column {$column}");
    }
});

it('rolls back and re-applies cleanly (index dropped before its column)', function () {
    $migration = billingMigration();

    // down() must not throw on the indexed plan_id column.
    $migration->down();

    expect(Schema::hasColumn('companies', 'plan_id'))->toBeFalse()
        ->and(Schema::hasColumn('companies', 'subscription_ends_at'))->toBeFalse();

    // up() re-applies, and is idempotent (hasColumn guards) on a second run.
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('companies', 'plan_id'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'trial_ends_at'))->toBeTrue();
});
