<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * The phase 7 migration adds the owner-archive column to `companies`. Pins that
 * it applies and that down()/up() round-trips cleanly and idempotently. No index,
 * so down() drops the column directly.
 */

function companyArchiveMigration(): object
{
    return require __DIR__.'/../../../database/migrations/2026_07_07_000000_add_company_archived_at_to_companies_table.php';
}

it('adds the company_archived_at column to companies', function () {
    expect(Schema::hasColumn('companies', 'company_archived_at'))->toBeTrue();
});

it('rolls back and re-applies cleanly and idempotently', function () {
    $migration = companyArchiveMigration();

    $migration->down();

    expect(Schema::hasColumn('companies', 'company_archived_at'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('companies', 'company_archived_at'))->toBeTrue();
});
