<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier-Paddle's `customers` table, owned by the add-on under a `paddle_` prefix
 * so it cannot collide with Cashier-Stripe's tables in a host that runs both
 * gateways (B4 follow-up). Faithful copy of the vendor migration; only the table
 * name changes. Loaded only when the Paddle gateway is configured (see the service
 * provider's registerPaddleModels()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paddle_customers')) {
            return;
        }

        Schema::create('paddle_customers', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('paddle_id')->unique();
            $table->string('name');
            $table->string('email');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paddle_customers');
    }
};
