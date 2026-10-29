<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCodeRedemption;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\AdminUser;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The super-admin promo-code console (B7b) — the CRUD UI over the B5 backend. Proves the
 * MANAGE_ABILITY gate is now defined (store authorises), the deferred B5 FIX3 percentage
 * cap is covered now that a route exists, and every action is super-admin-gated.
 */
function billingPromoAdmin(): AdminUser
{
    return AdminUser::query()->create([
        'name' => 'Root',
        'email' => 'root@example.test',
        'password' => bcrypt('secret'),
    ]);
}

function makeCode(array $attrs = []): PromoCode
{
    return PromoCode::query()->create(array_merge([
        'code' => 'SAVE10',
        'kind' => PromoCode::KIND_DISCOUNT,
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'single_use_per_user' => true,
        'is_active' => true,
    ], $attrs));
}

it('renders the promo console for a super-admin', function () {
    makeCode();

    $this->actingAs(billingPromoAdmin())
        ->get('/admin/promo-codes', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/PromoCodes/Index')
        ->assertJsonStructure(['props' => ['promo_codes' => ['data'], 'filters', 'pagination']]);
});

it('counts redemptions as total_uses', function () {
    $code = makeCode(['code' => 'USED']);
    PromoCodeRedemption::query()->create(['promo_code_id' => $code->id, 'user_id' => 1, 'kind' => 'discount']);
    PromoCodeRedemption::query()->create(['promo_code_id' => $code->id, 'user_id' => 2, 'kind' => 'discount']);

    $row = collect($this->actingAs(billingPromoAdmin())
        ->get('/admin/promo-codes', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.promo_codes.data'))
        ->firstWhere('code', 'USED');

    expect($row['total_uses'])->toBe(2);
});

it('filters by status and type', function () {
    makeCode(['code' => 'ACTIVEGEN']);
    makeCode(['code' => 'OFFPERSONAL', 'is_active' => false, 'user_id' => 7]);

    $active = collect($this->actingAs(billingPromoAdmin())
        ->get('/admin/promo-codes?status=active&type=general', ['X-Inertia' => 'true'])
        ->json('props.promo_codes.data'));

    expect($active->pluck('code')->all())->toContain('ACTIVEGEN')
        ->and($active->pluck('code')->all())->not->toContain('OFFPERSONAL');
});

it('renders the create page', function () {
    $this->actingAs(billingPromoAdmin())
        ->get('/admin/promo-codes/create', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/PromoCodes/Create')
        ->assertJsonStructure(['props' => ['discount_types', 'kinds']]);
});

it('stores a new code (gate is now defined)', function () {
    $this->actingAs(billingPromoAdmin())
        ->post('/admin/promo-codes', [
            'code' => 'SPRING25',
            'kind' => PromoCode::KIND_DISCOUNT,
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'single_use_per_user' => true,
            'is_active' => true,
        ])
        ->assertRedirect(route('admin.promo-codes.index'));

    expect(PromoCode::query()->where('code', 'SPRING25')->exists())->toBeTrue();
});

it('ignores non-fillable fields in the store request (mass-assignment safe)', function () {
    $this->actingAs(billingPromoAdmin())->post('/admin/promo-codes', [
        'code' => 'NOINJECT',
        'kind' => PromoCode::KIND_DISCOUNT,
        'discount_type' => 'fixed',
        'discount_value' => 500,
        'used_count' => 999,
        'single_use_per_user' => true,
        'is_active' => true,
    ])->assertRedirect();

    expect(PromoCode::query()->where('code', 'NOINJECT')->value('used_count'))->toBe(0);
});

it('rejects a percentage discount over 100 on store (B5 FIX3)', function () {
    $this->actingAs(billingPromoAdmin())->post('/admin/promo-codes', [
        'code' => 'TOOMUCH',
        'kind' => PromoCode::KIND_DISCOUNT,
        'discount_type' => 'percentage',
        'discount_value' => 150,
        'single_use_per_user' => true,
        'is_active' => true,
    ])->assertSessionHasErrors('discount_value');

    expect(PromoCode::query()->where('code', 'TOOMUCH')->exists())->toBeFalse();
});

it('rejects a percentage over 100 on a partial update with no type submitted (B5 FIX3 fallback)', function () {
    $code = makeCode(['code' => 'PCT', 'discount_type' => 'percentage', 'discount_value' => 10]);

    $this->actingAs(billingPromoAdmin())
        ->put("/admin/promo-codes/{$code->id}", ['discount_value' => 151])
        ->assertSessionHasErrors('discount_value');

    expect($code->fresh()->discount_value)->toBe(10);
});

it('updates an editable field', function () {
    $code = makeCode(['code' => 'EDITME', 'description' => 'old']);

    $this->actingAs(billingPromoAdmin())
        ->put("/admin/promo-codes/{$code->id}", ['description' => 'new note'])
        ->assertRedirect(route('admin.promo-codes.index'));

    expect($code->fresh()->description)->toBe('new note');
});

it('toggles active state', function () {
    $code = makeCode(['code' => 'TOGGLE', 'is_active' => true]);

    $this->actingAs(billingPromoAdmin())->patch("/admin/promo-codes/{$code->id}/toggle");

    expect($code->fresh()->is_active)->toBeFalse();
});

it('searches users for a personal code', function () {
    PaymentUser::query()->create(['name' => 'Grace', 'lastname' => 'Hopper', 'email' => 'grace@example.test', 'password' => bcrypt('x')]);

    $results = $this->actingAs(billingPromoAdmin())
        ->getJson('/admin/promo-codes/search-users?search=Hopper')
        ->assertOk()
        ->json();

    expect($results)->toHaveCount(1)
        ->and($results[0])->toHaveKeys(['id', 'email', 'fullname'])
        ->and($results[0]['fullname'])->toBe('Grace Hopper');
});

it('returns an empty list for a too-short user search', function () {
    $this->actingAs(billingPromoAdmin())
        ->getJson('/admin/promo-codes/search-users?search=a')
        ->assertOk()
        ->assertExactJson([]);
});

it('denies every promo action to a non-admin', function () {
    $joe = PaymentUser::query()->create(['name' => 'Joe', 'email' => 'joe@example.test', 'password' => bcrypt('x')]);
    $code = makeCode(['code' => 'GUARDED']);

    $this->actingAs($joe)->get('/admin/promo-codes')->assertStatus(403);
    $this->actingAs($joe)->get('/admin/promo-codes/create')->assertStatus(403);
    $this->actingAs($joe)->post('/admin/promo-codes', ['code' => 'X'])->assertStatus(403);
    $this->actingAs($joe)->patch("/admin/promo-codes/{$code->id}/toggle")->assertStatus(403);
    $this->actingAs($joe)->getJson('/admin/promo-codes/search-users?search=anything')->assertStatus(403);
});
