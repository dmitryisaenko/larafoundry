<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The promo-code registry (sub-phase B5), owned by the add-on.
 *
 * Ported from the donor's `promo_codes` table (kohana_legacy
 * 2025_11_07_222924_create_promo_codes_table) with the deliberate B5 changes:
 *
 *  - `discount_value` is an INTEGER, not the donor's `decimal:2`. Money in this
 *    add-on is minor units (the core's `PlanContract::priceFor(): ?int` is cents),
 *    and a percentage is a whole 0–100. The donor stored a float because it
 *    computed the charge IN the app over a FAKE payment; here the gateway computes
 *    every charged amount, so the only in-app use of `discount_value` is a UI
 *    preview (see PromoCode::calculateDiscount, marked estimate-only).
 *  - `kind` distinguishes a `discount` code (maps to a gateway coupon) from a
 *    `free_month` code (grants an app-side trial, no gateway). Decisions D-B5-1/2.
 *  - `stripe_coupon_id` / `paddle_discount_id` carry the gateway's catalogue id the
 *    registry maps onto — the hybrid: our registry owns validity/personal/single-use,
 *    the gateway owns the charged amount (D-B5-1).
 *  - `user_id` / `company_id` scoping columns are PLAIN nullable + index, NOT
 *    `constrained()`. The add-on does not know the host's users/companies schema or
 *    key type, so it never declares a cross-package foreign key (same stance as the
 *    Stripe-columns migration). A personal code sets `user_id`; null = a public code.
 *
 * Idempotent and table-guarded, matching the core/add-on migration style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promo_codes')) {
            return;
        }

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();

            // Personal-code owner (nullable = public code). Plain column: the add-on
            // never constrains against the host's users table.
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('code', 50)->unique();
            $table->string('description')->nullable();

            // discount = a gateway-applied coupon; free_month = an app-side trial grant.
            $table->string('kind', 20)->default('discount');

            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            // Whole units: percentage 0–100, or fixed minor units (cents). Preview-only
            // in the app; the gateway is the source of truth for the charged amount.
            $table->integer('discount_value')->default(0);

            // The gateway catalogue ids this registry code maps onto (D-B5-1 hybrid).
            // Nullable: a free_month code needs neither; a discount code needs the one
            // for the gateway the tenant's region routes to.
            $table->string('stripe_coupon_id')->nullable();
            $table->string('paddle_discount_id')->nullable();

            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('single_use_per_user')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        // up() skips a `promo_codes` table the host already owns (a host with its own
        // promo codes predating the core's), so drop only the core's own shape.
        if (! Schema::hasTable('promo_codes') || ! Schema::hasColumn('promo_codes', 'discount_type')) {
            return;
        }

        Schema::dropIfExists('promo_codes');
    }
};
