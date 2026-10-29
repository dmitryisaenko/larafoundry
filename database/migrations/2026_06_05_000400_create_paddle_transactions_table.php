<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier-Paddle's `transactions` table, owned by the add-on as
 * `paddle_transactions` (B4 follow-up). Faithful copy; only the table name changes.
 * `paddle_subscription_id` keeps its vendor name (it relates to
 * paddle_subscriptions.paddle_id, a column key, unaffected by the table rename).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paddle_transactions')) {
            return;
        }

        Schema::create('paddle_transactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('paddle_id')->unique();
            $table->string('paddle_subscription_id')->nullable()->index();
            $table->string('invoice_number')->nullable();
            $table->string('status');
            $table->string('total');
            $table->string('tax');
            $table->string('currency', 3);
            $table->timestamp('billed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paddle_transactions');
    }
};
