<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Support tickets (phase 4.2).
 *
 * Prefixed `larafoundry_` for the same reason as the notification store: the
 * core owns these tables and a host's own `tickets` table (if any) never
 * collides. Self-contained — the donor's `coderflex/laravel-ticket` schema is
 * NOT used (decision D-4.2-1). Categories/labels are JSON slug arrays driven by
 * config (decision D-4.2-5), so there are no category/label/pivot tables. No
 * `assigned_to` (decision D-4.2-4) and no `company_id` — tickets are a
 * user↔operator channel, not a tenant feature (decision D-4.2-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_tickets', function (Blueprint $table) {
            $table->id();
            // User-facing URLs use the uuid, never the sequential id.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->index();
            $table->string('title');
            $table->text('message');
            $table->string('priority')->default('standard');
            $table->string('status')->default('wait-moderator');
            // Config-driven slug arrays (decision D-4.2-5).
            $table->json('categories')->nullable();
            $table->json('labels')->nullable();
            $table->timestamps();

            // The user inbox filters by owner + status; the admin list sorts by
            // status. Composite covers the owner query, plain index the console.
            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_tickets');
    }
};
