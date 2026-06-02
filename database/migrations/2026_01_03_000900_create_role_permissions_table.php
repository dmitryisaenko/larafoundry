<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `role_permissions` pivot — which permissions a role grants (phase 1.3).
 *
 * Plain many-to-many; no tenant column here (the tenant scope lives on the role
 * row's `company_id`). Both FKs cascade on delete so dropping a role or a
 * permission cleans up its links. Unique per (role_id, permission_id).
 *
 * Guarded with `hasTable` to stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('role_permissions')) {
            return;
        }

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
