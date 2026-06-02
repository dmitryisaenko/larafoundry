<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending employee invitations to a company (phase 1.2).
 *
 * An owner invites an email; we store a cryptographically strong, unique token
 * and an expiry. On accept we verify the logged-in user's email matches the
 * invited email (anti email-spoof, donor guard kept) and that the invite hasn't
 * expired. `role_id` is intentionally absent — role-on-invite is RBAC (phase 1.3).
 *
 * `status` tracks the lifecycle (pending/accepted/rejected). The (company_id,
 * email) pair is unique so the same address can't be invited twice to one
 * company while pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_invitations')) {
            return;
        }

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->string('status')->default('pending');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            // NOT NULL on purpose: every invite has a hard TTL. A null expiry
            // would be a never-expiring token (the model treats null as expired
            // too, as belt-and-suspenders, but the schema forbids it).
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
    }
};
