<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier-Paddle's `subscriptions` table, owned by the add-on as
 * `paddle_subscriptions` to avoid colliding with Cashier-Stripe's `subscriptions`
 * (B4 follow-up). Faithful copy; only the table name changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paddle_subscriptions')) {
            return;
        }

        Schema::create('paddle_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('type');
            $table->string('paddle_id')->unique();
            $table->string('status');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paddle_subscriptions');
    }
};
