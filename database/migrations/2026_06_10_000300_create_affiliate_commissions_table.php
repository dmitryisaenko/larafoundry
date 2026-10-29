<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate commissions — the payout ledger (Ф4.4), owned by the add-on.
 *
 * One row per qualifying successful payment of a referred company. Accrued by the
 * AccrueAffiliateCommission listener on the add-on's CompanyPaymentProcessed event;
 * a super-admin moves it pending -> approved -> paid in the console, or it is voided
 * (clawback) when the same payment later fails/refunds.
 *
 *  - `affiliate_id` / `referral_id` are in-package FKs (cascade with their parents).
 *  - `company_payment_id` is an in-package FK AND unique: one commission per payment.
 *    The uniqueness is the idempotency backstop for the accrual listener (a re-delivered
 *    webhook updates the same row, never double-pays).
 *  - `company_id` is a denormalised PLAIN cross-package column for reporting — the
 *    TENANT key (a company id in `teams` mode, a user id in `personal`).
 *  - `amount` is whole MINOR UNITS in the PAYMENT's `currency` — never converted across
 *    currencies (per-currency totals only, like B9).
 *  - `basis` / `rate_bp` snapshot how the amount was derived, for audit.
 *
 * Loads after `company_payments` (an earlier 2026_06_05 migration), so the FK resolves.
 * Idempotent and table-guarded, matching the core/add-on migration style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('affiliate_commissions')) {
            return;
        }

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('affiliate_id')->constrained('affiliates')->cascadeOnDelete();
            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();

            // One commission per payment — the in-package FK is unique, the idempotency
            // backstop. Cascade so removing a payment row removes its commission.
            $table->foreignId('company_payment_id')->constrained('company_payments')->cascadeOnDelete();
            $table->unique('company_payment_id');

            // Denormalised TENANT KEY for reporting (plain, cross-package): a company id
            // in `teams` mode, a user id in `personal` — read the name as "tenant_id".
            $table->unsignedBigInteger('company_id');

            $table->string('currency', 3);

            // Commission in whole MINOR UNITS, in the PAYMENT's currency (no conversion).
            $table->unsignedBigInteger('amount')->default(0);

            $table->enum('status', ['pending', 'approved', 'paid', 'void'])->default('pending');

            // Audit snapshot of how the amount was derived.
            $table->string('basis');
            $table->unsignedInteger('rate_bp')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_reference')->nullable();

            $table->timestamps();

            // Covers the common "this affiliate's commissions by status" payout read.
            $table->index(['affiliate_id', 'status']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
    }
};
