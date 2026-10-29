<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit wallets (v0.46.0): one per payer.
 *
 * The owner is polymorphic because the payer depends on the tenancy mode - the
 * user in `personal`, the company in `teams` (the same rule as BillingSubject).
 * A wallet of its own, rather than a column on the host's users/companies table,
 * gives every balance change one row to lock and keeps the core off host tables.
 *
 * `balance` is signed: an operator's work may be held past zero on purpose
 * (CreditService allowNegative). The invariant the module keeps is
 * balance == SUM(larafoundry_credit_transactions.amount) for the wallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->bigInteger('balance')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_credit_wallets');
    }
};
