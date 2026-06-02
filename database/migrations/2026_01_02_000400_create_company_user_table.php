<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `company_user` pivot — membership of a user in a company (phase 1.2).
 *
 * One row per (company, user). `is_owner` marks founders/owners; `is_deleted`
 * soft-removes a member without losing the audit trail (who added them, when a
 * removal was requested). `default_route` remembers the landing route per
 * membership. Role assignment is NOT here — RBAC arrives in phase 1.3.
 *
 * `removal_requested_by` / `added_by_id` nullOnDelete so removing the actor
 * never cascades away the membership record itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_user')) {
            return;
        }

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('removal_requested_at')->nullable();
            $table->foreignId('removal_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('default_route', 100)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'is_owner']);
            $table->index(['user_id', 'is_deleted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
