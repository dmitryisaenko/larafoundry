<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fortify's two-factor columns.
 *
 * In a real host these are published by Fortify's own migration; the package
 * does not own that schema. The test-suite stands them in here so the
 * IsLaraFoundryUser trait's TwoFactorAuthenticatable behaviour and the hidden
 * two_factor_* columns have a backing table. Kept ahead of the package's
 * 2026_* ALTER so its `after('password')` placement does not collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->after('password')->nullable();
            $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
