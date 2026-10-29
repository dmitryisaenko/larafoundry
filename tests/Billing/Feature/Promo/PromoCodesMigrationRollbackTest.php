<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * up() skips a `promo_codes` table the host already owns (comentor-back has its own,
 * with a different schema), so down() must not drop that table on rollback.
 */
uses(RefreshDatabase::class);

function promoCodesMigration(): object
{
    return require __DIR__.'/../../../../database/migrations/2026_06_05_001000_create_promo_codes_table.php';
}

it('rollback drops the promo_codes table the migration created', function () {
    expect(Schema::hasColumn('promo_codes', 'discount_type'))->toBeTrue();

    Schema::disableForeignKeyConstraints();
    promoCodesMigration()->down();
    Schema::enableForeignKeyConstraints();

    expect(Schema::hasTable('promo_codes'))->toBeFalse();
});

it('rollback leaves a host-owned promo_codes table alone', function () {
    Schema::disableForeignKeyConstraints();
    Schema::drop('promo_codes');
    Schema::create('promo_codes', function (Blueprint $table) {
        $table->id();
        $table->string('code', 40)->unique();
        $table->unsignedInteger('comentokens');
    });

    promoCodesMigration()->down();
    Schema::enableForeignKeyConstraints();

    expect(Schema::hasTable('promo_codes'))->toBeTrue()
        ->and(Schema::hasColumn('promo_codes', 'comentokens'))->toBeTrue();
});
