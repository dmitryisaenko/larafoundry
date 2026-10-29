<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\BillableTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier's billing-identity columns on the CONFIGURED billable table (sub-phase B3).
 *
 * Laravel Cashier stores the Stripe customer id and the default payment method on
 * the Billable model's table; without them `newSubscription()` /
 * `createAsStripeCustomer()` cannot work. Which table that is depends on the mode:
 * `larafoundry-billing.billable_model` is the host's Company subclass in `teams`
 * (so the columns land on `companies`, as they always have) and its User class in
 * `personal`, where the payer is a person and the columns belong on `users`. The
 * file name still says `companies` because renaming a migration re-runs it on every
 * installed host; the target is read from config, not from the name.
 *
 * Paddle needs no counterpart here: Cashier-Paddle keeps its customer identity in
 * its own `paddle_customers` table (a polymorphic `billable` morph), so it adapts to
 * a User billable with no schema change at all.
 *
 * The core's own billing migration (phase 3.1) noted it "adds no column of its
 * own to companies" — it doesn't; THIS migration ships with the PAID add-on, and
 * Cashier's hard requirement is the one documented exception to keeping
 * cross-package ALTERs on the core table at zero. The add-on owning the migration
 * keeps the free core gateway-agnostic (it never learns Stripe exists).
 *
 * `stripe_id` carries a plain index, not a DB-level unique, mirroring Cashier's
 * own stock migration: uniqueness is an app-layer concern (Cashier resolves a
 * billable from a webhook via `findBillable($stripeId)`), and a plain index keeps
 * the rollback friction-free on SQLite. Every column is guarded with `hasColumn`
 * so the migration is idempotent, matching the core's style.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = BillableTable::for(BillableTable::STRIPE);

        if ($table === null || ! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'stripe_id')) {
                $blueprint->string('stripe_id')->nullable()->index();
            }

            if (! Schema::hasColumn($table, 'pm_type')) {
                $blueprint->string('pm_type')->nullable();
            }

            if (! Schema::hasColumn($table, 'pm_last_four')) {
                $blueprint->string('pm_last_four', 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        $table = BillableTable::for(BillableTable::STRIPE);

        if ($table === null || ! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            // stripe_id carries a standalone index (added in up()); drop it before
            // the column so a rollback doesn't error on a driver that refuses to
            // drop a column still referenced by an index (SQLite/strict setups).
            if (Schema::hasColumn($table, 'stripe_id')) {
                $blueprint->dropIndex(['stripe_id']);
            }

            foreach ([
                'stripe_id',
                'pm_type',
                'pm_last_four',
            ] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
