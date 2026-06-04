<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level block columns on `companies` (phase 3.3).
 *
 * The donor blocked a whole company only INDIRECTLY — by banning its owner
 * (recon §B); there was no admin operation to block a company on its own. This
 * adds a first-class company block the super-admin console can set, mirroring
 * the user block on the user model (`user_blocked_at`): a timestamp plus an
 * optional human-readable reason.
 *
 * The block is enforced at the tenancy boundary (EnsureActiveTenant): when the
 * blocked company is a user's active tenant, the company screens are denied —
 * the cascade that takes every member down with the company.
 *
 * Like the billing columns, these are written server-side only and are NOT in
 * the model's $fillable, so a host can never let a tenant unblock itself.
 *
 * Each column is guarded with `hasColumn` so the migration is idempotent and
 * safe to re-run, matching the companies migration's style. No index is added,
 * so down() can drop the columns directly (unlike the billing plan_id index).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'company_blocked_at')) {
                $table->timestamp('company_blocked_at')->nullable();
            }

            if (! Schema::hasColumn('companies', 'company_blocked_reason')) {
                // Free-text operator note ("payment dispute", "abuse", …). Plain
                // string rather than a code: companies are few and a human reading
                // the console wants words, not a lookup table.
                $table->string('company_blocked_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            foreach (['company_blocked_at', 'company_blocked_reason'] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
