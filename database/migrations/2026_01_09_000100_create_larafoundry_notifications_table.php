<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * In-app notification store (phase 4.1).
 *
 * Prefixed `larafoundry_` on purpose: Laravel reserves the bare `notifications`
 * table for its own database-channel (a different, uuid-keyed schema). The core
 * does not use that channel, so the two never coexist — the prefix keeps a host
 * free to run `notifications:table` for its own Laravel notifications.
 *
 * A single row can be one ADMIN broadcast fanned out to many users (via the
 * pivot) or one SYSTEM notification — `notification_type` distinguishes them.
 * System wording is translation-keys (`title_key`/`body_key` + `params`); admin
 * wording is per-locale JSON the operator typed (`*_translations`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('code')->index();
            $table->enum('notification_type', ['admin', 'system'])->default('admin')->index();
            $table->enum('status', ['draft', 'sending', 'sent'])->default('draft')->index();

            // System notifications: translation keys resolved at read time.
            $table->string('title_key')->nullable();
            $table->text('body_key')->nullable();
            $table->json('params')->nullable();

            // Admin broadcasts: per-locale text the operator authored.
            $table->json('title_translations')->nullable();
            $table->json('body_translations')->nullable();

            // Admin broadcast audience snapshot + UI payload (actions, context).
            $table->json('recipient_filters')->nullable();
            $table->json('data')->nullable();

            $table->timestamp('visible_from')->nullable();
            $table->timestamp('visible_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_notifications');
    }
};
