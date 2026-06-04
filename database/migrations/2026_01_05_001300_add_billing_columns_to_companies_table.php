<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The billing seam columns on `companies` (phase 3.1, decision D3.1-a).
 *
 * The companies migration (phase 1.2) deliberately left these out with a note
 * that they "arrive in phase 3 with the billing module" — this is that module.
 * The names match the donor 1:1 so the future paid-add-on migrations (payments,
 * promo codes) line up without a rename.
 *
 * They live in the FREE core (not the add-on) so the access gate
 * (`Company::hasAccess()`) and RBAC can read real subscription state with no
 * add-on installed, and a free self-host can grant a trial by hand. The add-on
 * only WRITES them (its Cashier webhook sets `subscription_ends_at`); it adds no
 * column of its own to `companies` — keeping cross-package ALTERs on this core
 * table at zero.
 *
 * Each column is guarded with `hasColumn` so the migration is idempotent and safe
 * to re-run, matching the companies migration's style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'plan_id')) {
                // String identifier (decision D3.1-b), not a FK: the core does not
                // own a plans table — the plan source sits behind PlanRepository.
                $table->string('plan_id')->nullable()->index();
            }

            if (! Schema::hasColumn('companies', 'billing_period')) {
                // 'monthly' | 'yearly'. A plain string, validated at the edge by
                // the add-on, so the core needs no enum migration to add periods.
                $table->string('billing_period', 20)->nullable();
            }

            if (! Schema::hasColumn('companies', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable();
            }

            if (! Schema::hasColumn('companies', 'subscription_ends_at')) {
                $table->timestamp('subscription_ends_at')->nullable();
            }

            if (! Schema::hasColumn('companies', 'free_month_used_at')) {
                // Storage for the one-free-month offer. The offer itself is an
                // add-on feature; the column lives here so the add-on never has to
                // ALTER this core table to record it.
                $table->timestamp('free_month_used_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            // plan_id carries a standalone index (added in up()); drop it before
            // the column so a rollback doesn't error on a driver that refuses to
            // drop a column still referenced by an index (SQLite/strict setups).
            if (Schema::hasColumn('companies', 'plan_id')) {
                $table->dropIndex(['plan_id']);
            }

            foreach ([
                'plan_id',
                'billing_period',
                'trial_ends_at',
                'subscription_ends_at',
                'free_month_used_at',
            ] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
