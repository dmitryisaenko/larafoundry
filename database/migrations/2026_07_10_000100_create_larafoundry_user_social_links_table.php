<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * User social/profile links (phase 3b).
 *
 * A dedicated `larafoundry_user_social_links` table (decision 2026-07-10) rather
 * than a JSON column on `users`: a link is a first-class row with a stable order
 * (`sort`) and a FK that cascades when the user is removed. Prefixed
 * `larafoundry_` for the same reason as the other core tables — the package owns
 * it and a host's own table never collides. Guarded so the migration is
 * idempotent (re-runnable, tolerant of a host that already created it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('larafoundry_user_social_links')) {
            return;
        }

        Schema::create('larafoundry_user_social_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->index()
                ->constrained('users')
                ->onDelete('cascade');
            $table->string('platform', 50);
            $table->string('url', 500);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_user_social_links');
    }
};
