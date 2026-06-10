<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional role-on-invite to `company_invitations` (role-at-invite).
 *
 * `role_id` is the company-scoped role to grant the invitee on acceptance. It is
 * nullable on purpose: the default is "no role" ("Specify later"), preserving the
 * original email-only invite behaviour. `nullOnDelete` so deleting a role between
 * invite and acceptance simply drops the assignment rather than orphaning an FK —
 * the accept flow then no-ops the role grant.
 *
 * Guarded with `hasColumn` to stay idempotent on re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_invitations', 'role_id')) {
            return;
        }

        Schema::table('company_invitations', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->nullable()
                ->after('email')
                ->constrained('roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_invitations', 'role_id')) {
            return;
        }

        Schema::table('company_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
