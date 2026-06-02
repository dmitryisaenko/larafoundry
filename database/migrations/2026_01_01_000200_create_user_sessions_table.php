<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracked login sessions — one row per authenticated device.
 *
 * Owned by the core (the host has no equivalent), so this is a plain create.
 * Keyed to the framework `session_id` for reconciliation with the `sessions`
 * table. Company binding from the legacy donor is omitted — it returns in phase
 * 1.2 as a real FK column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->index();
            $table->string('login_method')->default('native');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('user_device_type')->nullable();
            $table->string('user_device_name')->nullable();
            $table->string('user_os')->nullable();
            $table->string('user_browser')->nullable();
            $table->timestamp('last_activity')->nullable();
            $table->text('last_route_name')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
