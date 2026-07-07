<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-driven company archiving on `companies` (phase 7 host request).
 *
 * Distinct from the super-admin company BLOCK (`company_blocked_at`, phase 3.3):
 * the block is a platform-operator cascade that takes EVERY member offline,
 * including the owner. Archiving is an OWNER action with the opposite access
 * semantics — the owner keeps full reach into an archived company (so they can
 * unarchive it and read its data), while non-owner members are locked out until
 * it is restored. The two columns are independent and can both be set.
 *
 * Written server-side only (owner archive/unarchive controller, via forceFill)
 * and NOT in the model's $fillable — a host must never let a tenant flip its own
 * archived state through mass assignment. Guarded with `hasColumn` so the
 * migration is idempotent, matching the block-columns migration's style. No
 * index, so down() drops the column directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'company_archived_at')) {
                $table->timestamp('company_archived_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'company_archived_at')) {
                $table->dropColumn('company_archived_at');
            }
        });
    }
};
