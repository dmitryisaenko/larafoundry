<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\BillableTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The active subscription's billing period on the CONFIGURED billable table(s)
 * (sub-phase B7a).
 *
 * The donor's owner billing UI reads `billing_period` to show whether the live
 * subscription is monthly or yearly. On `companies` the CORE already creates it (its
 * phase-3.1 billing migration), so there this migration has always been a no-op — it
 * earns its keep on any OTHER billable table: in `personal` mode the payer is a user,
 * and the core never puts billing columns on `users`.
 *
 * The period belongs to the paying SUBJECT, whichever gateway charges it, so this
 * covers every configured billable table ({@see BillableTable::all()}): `companies`
 * in `teams` mode (unchanged from before — both config keys default to the core
 * Company), `users` in `personal`, and both when a host declares a separate Paddle
 * billable class on another table. The file name still says `companies` because
 * renaming a migration re-runs it on every installed host.
 *
 * It is a plain nullable string (no DB cast needed — a string reads back as a string),
 * populated by the payment listener / mirror when a subscription is (re)established.
 * Guarded with `hasColumn` so the migration is idempotent, matching the core's style.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (BillableTable::all() as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'billing_period')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('billing_period', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (BillableTable::all() as $table) {
            // Never drop it from the core's own table: there the column is created by
            // the CORE's phase-3.1 billing migration (so up() above is a no-op), and
            // rolling the add-on back must not strip a column the free core reads.
            if ($table === BillableTable::core()) {
                continue;
            }

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'billing_period')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('billing_period');
            });
        }
    }
};
