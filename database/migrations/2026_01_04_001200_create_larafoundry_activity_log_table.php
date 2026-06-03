<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform activity log (phase 2.1).
 *
 * This is the spatie/laravel-activitylog base schema PLUS the HTTP/device/geo
 * context columns the core records on every entry. It deliberately REPLACES the
 * stub spatie would publish: the core owns the schema in full, so the spatie
 * migration is never published into the host (one source of truth).
 *
 * There is intentionally NO `company_id` — the log is a platform (super-admin)
 * tool, not a tenant feature (decision D2.1-0). A platform operator reads it
 * globally; there is nothing to scope per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('activitylog.table_name') ?: 'activity_log';

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $table) {
            $table->bigIncrements('id');

            // --- spatie base columns ---
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();

            // --- HTTP / device context ---
            $table->text('user_agent')->nullable();
            $table->string('user_ip')->nullable();
            $table->string('user_device_type')->nullable();
            $table->string('user_device_name')->nullable();
            $table->string('user_os')->nullable();
            $table->string('user_browser')->nullable();
            $table->string('route_name')->nullable();
            $table->string('request_method')->nullable();
            $table->text('full_url')->nullable();
            $table->integer('response_code')->nullable();
            $table->boolean('is_successful')->default(true);

            // Emails of the actor(s) for events that fire WITHOUT an authenticated
            // user (e.g. a failed login) — stored as an array so a single event
            // can carry more than one candidate identity.
            $table->json('user_email')->nullable();

            // --- async geo enrichment (opt-out via config) ---
            $table->string('geo_country')->nullable();
            $table->string('geo_city')->nullable();
            $table->timestamp('geo_updated_at')->nullable();

            $table->timestamps();

            // The donor only indexed log_name. The admin viewer additionally
            // filters by causer (per-user log) and by created_at (hours filter +
            // sort), so index those too.
            $table->index('log_name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $table = config('activitylog.table_name') ?: 'activity_log';

        Schema::dropIfExists($table);
    }
};
