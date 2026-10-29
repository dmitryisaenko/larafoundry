<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals — the attribution snapshot linking a referred tenant to its affiliate
 * (Ф4.4). Written ONCE, when the tenant appears (the AttributeReferral listener: on the
 * core's CompanyCreated event in `teams` mode, on user registration in `personal`),
 * never on a later edit.
 *
 *  - `affiliate_id` is an in-package FK (the add-on owns `affiliates`); cascade so
 *    removing an affiliate removes its referrals.
 *  - `referred_company_id` is a PLAIN cross-package column (a tenant key) AND unique:
 *    a company has at most one referrer, and the uniqueness is the lock-once guard —
 *    a second attempt can never re-point an existing attribution to a different partner.
 *  - `source_code` snapshots the code used (an affiliate's `code` may be regenerated).
 *  - `window_ends_at` bounds the recurring-commission window (attributed_at +
 *    window_months); null when the trigger is first_payment or lifetime.
 *
 * Idempotent and table-guarded, matching the core/add-on migration style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referrals')) {
            return;
        }

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('affiliate_id')->constrained('affiliates')->cascadeOnDelete();

            // The referred tenant's KEY: a host company id in `teams` mode, a user id in
            // `personal` (read as "referred_tenant_id"; not renamed, that would break
            // installed hosts). Plain column (cross-package) and unique = one tenant,
            // one referrer, set once and never overwritten.
            $table->unsignedBigInteger('referred_company_id')->unique();

            $table->string('source_code');

            $table->timestamp('attributed_at');

            // End of the recurring-commission window; null for first_payment / lifetime.
            $table->timestamp('window_ends_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
