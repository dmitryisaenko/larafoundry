<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * The phase 3.3 migration adds two block columns to `companies`. These pin that
 * it applies and that down()/up() round-trips cleanly. No index is added, so the
 * down() can drop the columns directly.
 */

function companyBlockMigration(): object
{
    return require __DIR__.'/../../../database/migrations/2026_01_06_001400_add_company_block_columns_to_companies_table.php';
}

it('adds the company block columns to companies', function () {
    foreach (['company_blocked_at', 'company_blocked_reason'] as $column) {
        expect(Schema::hasColumn('companies', $column))->toBeTrue("missing column {$column}");
    }
});

it('rolls back and re-applies cleanly and idempotently', function () {
    $migration = companyBlockMigration();

    $migration->down();

    expect(Schema::hasColumn('companies', 'company_blocked_at'))->toBeFalse()
        ->and(Schema::hasColumn('companies', 'company_blocked_reason'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('companies', 'company_blocked_at'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'company_blocked_reason'))->toBeTrue();
});
