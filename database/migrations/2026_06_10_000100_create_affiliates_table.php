<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliates — one row per partner (Ф4.4), owned by the add-on.
 *
 * A partner IS a host user, so `user_id` is a PLAIN column (no cross-package FK — the
 * same stance as company_payments.user_id; the add-on does not know the host's users
 * key type). It is unique: a user has at most one affiliate identity.
 *
 * The referral `code` lives here rather than on the core `users` table on purpose —
 * installing the add-on must not bloat every host's users schema. In `auto` eligibility
 * the row is created lazily the first time a user needs their link
 * ({@see Affiliate::ensureFor()}).
 *
 * Idempotent and table-guarded, matching the core/add-on migration style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('affiliates')) {
            return;
        }

        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();

            // The partner = a host user. Plain column (cross-package); unique so one user
            // maps to at most one affiliate identity.
            $table->unsignedBigInteger('user_id')->unique();

            // The public referral code carried in the /r/{code} link.
            $table->string('code')->unique();

            // Per-affiliate commission override in BASIS POINTS (1500 = 15.00%), consulted
            // ONLY by the percent basis; null => the config default rate. Basis points keep
            // a fractional percent exact without introducing a float money path.
            $table->unsignedInteger('commission_rate_bp')->nullable();

            // active  => earns and attributes; disabled => inert; pending => awaiting a
            // super-admin's approval (self_serve + require_approval).
            $table->enum('status', ['active', 'disabled', 'pending'])->default('active');
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
