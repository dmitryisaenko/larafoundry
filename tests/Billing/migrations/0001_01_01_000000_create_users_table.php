<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal host-owned `users` skeleton for the add-on test-suite.
 *
 * Mirrors the core package's own test users migration: the core only ALTERs
 * `users` (it assumes the host's default Laravel migration creates it), so DB
 * tests here must stand that base table up first. The `0001_` prefix sorts it
 * ahead of every `2026_*` package migration. Only the columns the mirror tests
 * need — a founder row for companies.created_by_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The host's users table carries these; the B7b console searches them
            // (name/lastname/email) and shows user_country, so the test table mirrors them.
            $table->string('lastname')->nullable();
            $table->string('country')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            // Mirrors the host's user-ban column (the core IsLaraFoundryUser::isBlocked()
            // reads it) so the enforcement suite can exercise an owner-ban cascade.
            $table->timestamp('user_blocked_at')->nullable();
            // The operator flag the core's `withoutSuperAdmin()` list scope filters on
            // (the dashboard aggregates go through it), so the column it queries exists.
            $table->boolean('is_admin')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
