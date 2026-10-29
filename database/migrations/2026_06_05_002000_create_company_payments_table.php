<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment ledger (sub-phase B7a), owned by the add-on.
 *
 * Ported from the donor's `company_payments` table (kohana_legacy
 * 2025_11_07_222937_create_company_payments_table) with the add-on's deliberate
 * money and cross-package conventions:
 *
 *  - `amount` / `discount_amount` are whole MINOR UNITS (cents) as integers, NOT the
 *    donor's `decimal:2` dollars. Money in this add-on is minor units (the core's
 *    `PlanContract::priceFor(): ?int` is cents and the B5 promo `discount_value` is an
 *    int), and the gateway, not the app, is the source of truth for every charge — it
 *    reports cents on the webhook. Storing dollars here would create a second money
 *    representation to reconcile, the exact donor anti-pattern B5 dropped.
 *  - `company_id` / `user_id` are PLAIN columns (no `constrained()`): same
 *    cross-package stance as the promo_codes / promo_code_redemptions / Stripe-columns
 *    migrations — the add-on never declares a foreign key against the host's
 *    companies/users tables (it does not know their key type).
 *  - `promo_code_id` IS an in-package foreign key: the add-on owns `promo_codes`.
 *    `nullOnDelete` keeps the payment record intact if a code is later removed.
 *  - `payment_response` holds the gateway payload REDACTED of PII before storage (see
 *    {@see CompanyPayment} mutator).
 *  - `period_start` / `period_end` are nullable (the donor forced them NOT NULL over a
 *    fake always-success charge): a pending/failed row may have no confirmed window.
 *
 * Idempotent and table-guarded, matching the core/add-on migration style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_payments')) {
            return;
        }

        Schema::create('company_payments', function (Blueprint $table) {
            $table->id();

            // The TENANT KEY and the paying user. `company_id` is a company id in
            // `teams` mode and a USER id in `personal` mode (where the payer is the
            // person) — the name predates personal-mode support and is kept as-is
            // because renaming it would break every installed host; read it as
            // "tenant_id". Plain columns — the add-on does not constrain against the
            // host's companies/users tables.
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('plan_id');
            $table->enum('billing_period', ['monthly', 'yearly'])->default('monthly');

            // Whole minor units (cents). The gateway computes and reports the charge.
            // unsignedBigInteger (not unsignedInteger): a non-strict MySQL would SILENTLY
            // truncate an over-4.29e9-cents amount, corrupting the logged figure — 8
            // bytes removes that class of bug and matches PHP's 64-bit int on the model.
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->string('discount_reason')->nullable();

            // In-package FK (the add-on owns promo_codes). Null the link, not the row.
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();

            $table->enum('payment_status', ['pending', 'success', 'failed'])->default('pending');
            $table->string('payment_method')->nullable();

            // Gateway payload, PII-redacted on write. Nullable for a pending row.
            $table->json('payment_response')->nullable();

            $table->timestamp('paid_at')->nullable();

            // The covered service window (nullable: unknown for a pending/failed row).
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->timestamps();

            // Covers the common "this company's payments by status" read and, by its
            // leading column, "this company's payments" (so no standalone company_id).
            $table->index(['company_id', 'payment_status']);
            $table->index('user_id');
            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payments');
    }
};
