<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit purchases (v0.47.0): one row per payment that bought credits, whatever
 * took the money (Paddle, a mobile store, a local gateway).
 *
 * The row is what makes crediting safe to repeat: it is written in the SAME
 * transaction as the credit, under the payment's own unique `reference`, so a
 * re-delivered confirmation finds it and credits nothing - and a credit that
 * failed rolls the row back with it, so the retry credits after all.
 *
 * A gateway that opens its checkout on the server (Paddle) writes the row
 * `pending` at that moment, with the credits and the price the server set; the
 * payment's confirmation is checked against this row and never against data
 * that travelled with the payment (a buyer may be able to edit that).
 *
 * `refund_key` is what a refund names the payment by (for Paddle the same
 * transaction id; another gateway may refund by a different id than the one it
 * confirmed with). A refund that arrives before its purchase is confirmed is
 * kept (`refund_pending`; with no owner when there was no row yet), and the
 * confirmation, when it lands, settles it and credits nothing.
 *
 * `owner_*` is the wallet's owner (the payer); `user_id` is who pressed buy.
 * Plain columns - the core does not constrain against the host's tables.
 * `meta` is the host's own data attached to the purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('credits')->default(0);
            $table->unsignedBigInteger('credits_reversed')->default(0);
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('gateway', 32);
            $table->string('reference')->nullable();
            $table->string('refund_key')->nullable();
            $table->string('status', 16);
            $table->boolean('sandbox')->default(false);
            $table->string('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'reference']);
            $table->unique(['gateway', 'refund_key']);
            $table->index(['owner_type', 'owner_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_credit_purchases');
    }
};
