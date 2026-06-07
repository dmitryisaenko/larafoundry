<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic settings store (phase 5.1).
 *
 * One row per (scope, scope_id, key). `scope` is app|company|user; `scope_id`
 * is the owning company/user id (the sentinel '0' for app — a string so it fits
 * integer or uuid primary keys). Keeping scope_id NOT NULL (never NULL) is what
 * lets the unique index enforce one row per app key too — a NULL would slip past
 * it. `value` is the JSON-encoded value the repository decodes and casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larafoundry_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);
            $table->string('scope_id')->default('0');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'key']);
            $table->index(['scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larafoundry_settings');
    }
};
