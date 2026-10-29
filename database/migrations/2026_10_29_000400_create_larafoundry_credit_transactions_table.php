<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credit ledger (v0.46.0): one row per movement, never edited.
 *
 * `kind` is the core's own short list (hold, charge, release, credit, debit,
 * adjust) - what the movement DID to the wallet. `reason` is the host's word for
 * WHY (a purchase, a welcome gift, a document unlock), used for the row's label
 * and for reports; the core never interprets it.
 *
 * Two numbers, because a charge from a hold does not move the balance:
 *   - `amount` - how the BALANCE moved (signed). SUM(amount) == wallet balance.
 *   - `cost`   - for a charge, what the work cost (taken from the hold first,
 *                the shortfall from the balance - which `amount` then shows).
 * And `uncovered` - the part of a charge's cost neither the hold nor the balance
 * could pay: the work was done, unpaid. What was really paid is cost - uncovered.
 *
 * `value_cents` snapshots what the movement was worth at the time, so a later
 * price change never rewrites past rows. `subject` is what the movement concerns
 * (a hold copies its work here, so every movement of one job is one indexed
 * query). `meta` is the host's own free payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('larafoundry_credit_wallets')->cascadeOnDelete();
            $table->foreignId('hold_id')->nullable()->constrained('larafoundry_credit_holds')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('reason', 64)->nullable();
            $table->bigInteger('amount');
            $table->unsignedBigInteger('cost')->default(0);
            $table->unsignedBigInteger('uncovered')->default(0);
            $table->bigInteger('balance_after');
            $table->decimal('value_cents', 14, 4)->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_credit_transactions');
    }
};
