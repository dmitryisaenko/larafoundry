<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit holds (v0.46.0): credits set aside for one piece of work in progress.
 *
 * Holding moves the credits off the balance at once, so a long job cannot start
 * on a balance that another job spends meanwhile. The work is then charged from
 * the hold as it goes, and what is left is released back when it ends.
 *
 * `subject` is the work the hold belongs to (a host record: a job, an order) and
 * is unique - one hold per piece of work, which is what makes a repeated hold()
 * for the same work a no-op instead of a second deduction.
 *
 * `amount` is what was held, `remaining` what is still held, `spent` what the
 * work was charged in total - the sum of its charges' `cost`, so it may exceed
 * `amount` (the difference came from the balance, or was left `uncovered` on the
 * charge rows). `closed_at` marks a hold whose remainder has been released.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_credit_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('larafoundry_credit_wallets')->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('remaining')->default(0);
            $table->unsignedBigInteger('spent')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['wallet_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_credit_holds');
    }
};
