<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier-Paddle's `subscription_items` table, owned by the add-on as
 * `paddle_subscription_items` (B4 follow-up). Faithful copy; only the table name
 * changes. The `subscription_id` column keeps its vendor name so the inherited
 * Subscription::items() relation (which derives `subscription_id` from the model
 * basename) resolves correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paddle_subscription_items')) {
            return;
        }

        Schema::create('paddle_subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('product_id');
            $table->string('price_id');
            $table->string('status');
            $table->integer('quantity');
            $table->timestamps();

            $table->unique(['subscription_id', 'price_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paddle_subscription_items');
    }
};
