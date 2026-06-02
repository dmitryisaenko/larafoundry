<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The base `companies` table — the tenant in `teams` mode (phase 1.2).
 *
 * Deliberately MINIMAL (decision D1.2-a): only the columns every multi-tenant
 * SaaS needs. Business-specific columns (financial/contact details by country,
 * activity_type, employees_count, website, …) belong to the host and arrive via
 * a host-owned migration on top of this one.
 *
 * NOT here (decision D1.2-b): billing columns (trial_ends_at, subscription_*,
 * plan_id, billing_period, free_month_used_at). They arrive in phase 3 with the
 * billing module — we don't plant dead seam columns now.
 *
 * `uuid` is the route key (human-unfriendly id stays out of URLs); `slug` is
 * globally unique and reserved for future human-readable URLs. `created_by_id`
 * is the founder/owner. Guarded with `hasTable` to stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            return;
        }

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('country', 10)->nullable();
            $table->foreignId('created_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('logo')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
