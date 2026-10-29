<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\BillableTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The billing columns a NON-company billable needs (`personal` mode).
 *
 * The free core adds `plan_id` / `trial_ends_at` / `subscription_ends_at` /
 * `free_month_used_at` to `companies` (its phase-3.1 billing migration) because in
 * `teams` mode the payer is a company. In `personal` mode there are no companies:
 * the tenant — and therefore the payer — is the user, and the core never touches
 * `users` with billing columns (it stays gateway-agnostic and does not know a paid
 * add-on is installed). Without them the whole access axis is dead in that mode:
 * the webhook mirror has nowhere to write the paid-through date, the app-side free
 * month cannot be stamped, and `hasAccess()` has nothing to read.
 *
 * So the add-on fills exactly that gap, and ONLY that gap:
 *  - the core's `companies` table is skipped outright — the core owns those columns
 *    there, and this migration must never add or drop one behind its back;
 *  - every other configured billable table ({@see BillableTable::all()}: the Stripe
 *    and Paddle billables) gets the columns it is missing.
 *
 * With the shipped defaults both billable keys resolve to the core Company, so on an
 * existing `teams` install this migration touches nothing at all.
 *
 * It ALSO tops up the add-on's own earlier columns (`stripe_id` / `pm_type` /
 * `pm_last_four` from the B3 migration, `billing_period` from B7a) when they are
 * missing. Those migrations are the ones that own and roll back those columns; this is
 * purely a catch-up for a host that ran them BEFORE pointing `billable_model` at a User
 * — Laravel records a migration by file name and never re-runs it, so the columns would
 * otherwise never appear on the new table and Cashier would fail on a missing
 * `stripe_id`. On a fresh install every one of them already exists and this is a no-op.
 * (Converting a host that ALREADY HAS billing rows is a different matter and is not
 * supported — see the README: the tenant columns would then mix company ids with user
 * ids.)
 *
 * Column definitions mirror the core's 1:1 (a string `plan_id` with an index — the
 * core does not own a plans table, the plan source sits behind PlanRepository — and
 * nullable timestamps). Guarded with `hasColumn`, idempotent, matching the core.
 */
return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_values(array_filter(
            BillableTable::all(),
            fn (string $table): bool => $table !== BillableTable::core() && Schema::hasTable($table),
        ));
    }

    public function up(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'plan_id')) {
                    $blueprint->string('plan_id')->nullable()->index();
                }

                if (! Schema::hasColumn($table, 'trial_ends_at')) {
                    $blueprint->timestamp('trial_ends_at')->nullable();
                }

                if (! Schema::hasColumn($table, 'subscription_ends_at')) {
                    $blueprint->timestamp('subscription_ends_at')->nullable();
                }

                if (! Schema::hasColumn($table, 'free_month_used_at')) {
                    $blueprint->timestamp('free_month_used_at')->nullable();
                }

                // Catch-up for the earlier add-on migrations (see the class docblock).
                // They own the rollback of these, so down() below leaves them alone.
                if (! Schema::hasColumn($table, 'billing_period')) {
                    $blueprint->string('billing_period', 20)->nullable();
                }

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
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                // plan_id may carry the standalone index up() adds — drop it FIRST, and
                // only when it is really there. A host whose table already had a
                // `plan_id` (so up() skipped it, index and all) would otherwise hit
                // MySQL error 1091 "Can't DROP ...", which SQLite never reproduces and
                // no test would catch.
                if ($this->hasIndexOn($table, 'plan_id')) {
                    $blueprint->dropIndex(['plan_id']);
                }

                // NOTE: this drops the four columns whenever they exist, including on the
                // rare host that already had one before installing the add-on — a
                // migration cannot know which it created. `down()` on a table you brought
                // your own `plan_id` to therefore loses that column; back up first.
                foreach ([
                    'plan_id',
                    'trial_ends_at',
                    'subscription_ends_at',
                    'free_month_used_at',
                ] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }

    /**
     * Whether the table carries the index this migration would have created for a
     * column. Falls back to "no" when the driver cannot report indexes, so a rollback
     * degrades to skipping the drop rather than throwing.
     */
    private function hasIndexOn(string $table, string $column): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === "{$table}_{$column}_index") {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
