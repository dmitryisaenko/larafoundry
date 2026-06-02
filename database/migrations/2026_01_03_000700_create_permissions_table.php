<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `permissions` catalog — the atomic, app-wide grantable units (phase 1.3).
 *
 * `slug` is globally unique (dot notation `module.action`, e.g. `company.roles.view`)
 * and is the only thing the trait/gates ever check against. `module` groups slugs
 * for the management UI. Rows are seeded from `config/larafoundry-permissions.php`
 * via `larafoundry:permissions:sync` (idempotent updateOrCreate), NOT a seeder —
 * so the host can extend the catalog declaratively and re-sync.
 *
 * Guarded with `hasTable` to stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            return;
        }

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('module')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
