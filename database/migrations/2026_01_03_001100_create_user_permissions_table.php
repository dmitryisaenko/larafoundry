<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `user_permissions` pivot — individual grants/revocations (phase 1.3).
 *
 * Overrides roles for a single user: `is_revoked=false` is an extra grant on top
 * of their roles; `is_revoked=true` takes a permission away even if a role grants
 * it. The trait checks revocations BEFORE grants and roles (revoke wins).
 *
 * `company_id` is the TENANT scope (nullable → global). Unique per
 * (user_id, permission_id, company_id) so a user has at most one decision per
 * permission per company. `granted_by_id` is the actor (audit), nullOnDelete.
 *
 * Guarded with `hasTable` to stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_permissions')) {
            return;
        }

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->boolean('is_revoked')->default(false);
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id', 'company_id']);
            $table->index(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
