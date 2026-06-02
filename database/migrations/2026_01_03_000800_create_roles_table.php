<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `roles` table — named bundles of permissions (phase 1.3).
 *
 * Four kinds, distinguished by flags (NOT a `level` column — the hierarchy lives
 * in the trait's check priority, see HasRolesAndPermissions):
 *   - global    (is_global=true,  company_id=NULL) — e.g. `authenticated`, applies everywhere.
 *   - template  (is_template=true, company_id=NULL) — cloned into each new company.
 *   - company   (both flags false, company_id set)  — a template clone or a custom role.
 *   - custom    (is_custom=true,   company_id set)  — created by an owner in their company.
 *
 * `company_id` is the TENANT scope (nullable → global/template). Unique per
 * (company_id, slug): the same slug `member` can exist once globally as a template
 * and once per company as its clone. NULL company_id rows don't collide on the
 * unique index (NULL ≠ NULL in SQL) — slugs of global/template roles stay distinct
 * by being seeded once. `created_by_id` records the author of a custom role.
 *
 * RBAC pivots are deliberately NOT BelongsToTenant models (decision D1.3-f):
 * a global TenantScope would hide global/template roles when no tenant is active
 * and break super-admin. Isolation is the explicit `company_id` filter in the trait.
 *
 * Guarded with `hasTable` to stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            return;
        }

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_global')->default(false);
            $table->boolean('is_template')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
            $table->index(['is_global', 'is_template']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
