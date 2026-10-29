<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit + single-use ledger for promo-code redemptions (sub-phase B5).
 *
 * The donor enforced "one use per user" by querying successful `CompanyPayment`
 * rows — but the add-on has no `CompanyPayment` (deferred to B7), and that check
 * leaned on a FAKE always-success payment. B5 records each redemption (paid and
 * free-month) in its own ledger instead, which is also the audit trail security
 * asks for and the DB backstop against a race:
 *
 *  - `unique (promo_code_id, user_id)` is the database-level guarantee that a user
 *    cannot redeem the same code twice even under concurrent requests; the action's
 *    transaction + row lock is the app-level guard, this index is the last line.
 *  - `company_id` records WHICH tenant redeemed (the access grant lands there), so
 *    a free-month grant is auditable per company even when no user is attributed.
 *  - `gateway` / `gateway_reference` capture which PSP applied the discount and its
 *    id, for reconciliation against the webhook — never the charged amount (the
 *    add-on never stores a self-computed charge).
 *
 * Scoping columns are plain (no `constrained()`): same cross-package stance as the
 * promo_codes / Stripe-columns migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promo_code_redemptions')) {
            return;
        }

        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('promo_code_id')
                ->constrained('promo_codes')
                ->cascadeOnDelete();

            // The tenant that received the grant/discount, stored as the core's numeric
            // TENANT KEY: a company id in `teams` mode, a user id in `personal` (read
            // the column name as "tenant_id" — it is not renamed, that would break
            // installed hosts). Plain column — the add-on does not constrain against
            // the host's companies/users table.
            $table->unsignedBigInteger('company_id')->nullable()->index();

            // The user who redeemed (nullable: a free-month grant may be system-driven).
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('kind', 20)->default('discount');
            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable();

            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            // DB backstop for single-use-per-user against a concurrent double-redeem.
            $table->unique(['promo_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_redemptions');
    }
};
