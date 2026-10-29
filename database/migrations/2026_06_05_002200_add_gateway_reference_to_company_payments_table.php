<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Listeners\RecordStripePayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gateway's own charge reference on `company_payments` (sub-phase B7a).
 *
 * Added in its own migration (the create-table migration already shipped) so the
 * webhook recorder ({@see RecordStripePayment})
 * has an idempotency key: the Stripe invoice / Paddle transaction id. The unique
 * (gateway_reference, payment_status) index is the race backstop against a
 * double-delivered webhook — multiple NULLs are allowed, so manually-recorded rows
 * (which carry no gateway reference) never collide.
 *
 * Guarded with `hasColumn` so it is idempotent, matching the core/add-on style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_payments')) {
            return;
        }

        Schema::table('company_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('company_payments', 'gateway_reference')) {
                $table->string('gateway_reference')->nullable();
                $table->unique(['gateway_reference', 'payment_status']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('company_payments')) {
            return;
        }

        Schema::table('company_payments', function (Blueprint $table) {
            if (Schema::hasColumn('company_payments', 'gateway_reference')) {
                // Drop the unique index before the column so a strict driver
                // (SQLite) does not error on a column still referenced by an index.
                $table->dropUnique(['gateway_reference', 'payment_status']);
                $table->dropColumn('gateway_reference');
            }
        });
    }
};
