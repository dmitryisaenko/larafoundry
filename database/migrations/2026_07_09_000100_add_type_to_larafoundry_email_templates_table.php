<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-layer email templates (phase 2b): the marketing layer + a `type`.
 *
 * Additive over the phase-5.1 table. Every existing row is an override of a
 * registry (transactional) code, so `type` defaults to 'transactional' and the
 * new marketing-only columns (`name`, `variables`) are nullable — a transactional
 * row still reads its label + variable whitelist from the config registry.
 *
 * A MARKETING row is self-contained: it carries its own operator-facing `name`
 * and its own `variables` whitelist and needs no registry entry (that is the
 * whole point of the second layer). `code` stays unique across the table, and a
 * marketing code may never collide with a registry code (enforced in the
 * repository + FormRequest, not the schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('larafoundry_email_templates', function (Blueprint $table) {
            $table->string('type')->default('transactional')->index()->after('code');
            $table->string('name')->nullable()->after('type');
            $table->json('variables')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('larafoundry_email_templates', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'name', 'variables']);
        });
    }
};
