<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `user_roles` pivot — a user's role assignments, tenant-scoped (phase 1.3).
 *
 * `company_id` is the TENANT scope (nullable → a global role like `authenticated`,
 * which applies in every company). Unique per (user_id, role_id, company_id), so
 * the same role can be held in several companies. `assigned_by_id` is the actor
 * who granted it (audit), nullOnDelete so removing that actor never drops the
 * assignment. Role/user FKs cascade so the assignment dies with either side.
 *
 * Guarded with `hasTable` to stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_roles')) {
            return;
        }

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'company_id']);
            $table->index(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
