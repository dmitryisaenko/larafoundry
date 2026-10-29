<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableUser;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Access enforcement in `personal` mode: the `billing.access` middleware gates the
 * PERSON's own subscription, and `entitlement:<feature>` reads the plan off the same
 * subject. Both used to short-circuit open (no active company → pass through), which is
 * a paywall that never charges anyone.
 */
function enforcedUser(string $slug, array $attributes = []): BillableUser
{
    $user = BillableUser::query()->create([
        'name' => 'Person',
        'email' => "enf+{$slug}@example.test",
        'password' => bcrypt('secret'),
    ]);

    if ($attributes !== []) {
        $user->forceFill($attributes)->save();
    }

    return $user->refresh();
}

beforeEach(function () {
    Route::middleware('billing.access')->get('/app/work', fn () => response('ok'))->name('app.work');
    Route::get('/billing/blocked-page', fn () => response('blocked'))->name('billing.blocked');
    Route::middleware('entitlement:reports.advanced')->get('/app/reports', fn () => response('reports'));
});

it('lets a user with a live subscription through', function () {
    $user = enforcedUser('live', ['subscription_ends_at' => Carbon::now()->addMonth()]);

    $this->actingAs($user)->get('/app/work')->assertOk();
});

it('blocks an unpaid user in hard mode, as the owner of their own subscription', function () {
    config(['larafoundry-billing.enforcement.mode' => 'hard']);

    $this->actingAs(enforcedUser('unpaid'))
        ->get('/app/work')
        ->assertRedirect(route('billing.blocked', ['type' => 'payment_required']));
});

it('passes an unpaid user through in soft mode (the frontend alerts)', function () {
    config(['larafoundry-billing.enforcement.mode' => 'soft']);

    $this->actingAs(enforcedUser('soft'))->get('/app/work')->assertOk();
});

it('stays open when billing is disabled', function () {
    config(['larafoundry.billing.enabled' => false, 'larafoundry-billing.enforcement.mode' => 'hard']);

    $this->actingAs(enforcedUser('off'))->get('/app/work')->assertOk();
});

it('fails closed when the payer cannot answer hasAccess (host forgot BillingSubscriber)', function () {
    config(['larafoundry-billing.enforcement.mode' => 'hard']);

    // A user WITHOUT the BillingSubscriber trait: the host wired `personal` mode and a
    // Cashier-billable user, but left out the trait that reports subscription state.
    // Reading that silence as "has access" would be a paywall that never charges anyone,
    // so the gate must refuse instead.
    $payer = PaymentUser::query()->create([
        'name' => 'Traitless',
        'email' => 'traitless@example.test',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($payer)
        ->get('/app/work')
        ->assertRedirect(route('billing.blocked', ['type' => 'payment_required']));
});

it('stays open for a trait-less payer while billing is off', function () {
    // Fail-closed applies to the paywall, not to the FREE core: with billing off nothing
    // is gated, whatever the subject can or cannot answer.
    config(['larafoundry.billing.enabled' => false, 'larafoundry-billing.enforcement.mode' => 'hard']);

    $payer = PaymentUser::query()->create([
        'name' => 'Traitless',
        'email' => 'traitless-free@example.test',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($payer)->get('/app/work')->assertOk();
});

it('keeps the billing columns out of mass assignment', function () {
    // On `users` a host commonly runs `$guarded = []`, so a profile update would
    // otherwise hand the customer their own subscription.
    $user = BillableUser::query()->create([
        'name' => 'Person',
        'email' => 'massassign@example.test',
        'password' => bcrypt('secret'),
        'plan_id' => 'business',
        'subscription_ends_at' => Carbon::now()->addYears(10),
        'free_month_used_at' => null,
        'stripe_id' => 'cus_hijack',
    ]);

    $user->refresh();

    expect($user->plan_id)->toBeNull()
        ->and($user->subscription_ends_at)->toBeNull()
        ->and($user->stripe_id)->toBeNull()
        ->and($user->hasAccess())->toBeFalse()
        // The add-on's own writes go through forceFill and are unaffected.
        ->and($user->forceFill(['plan_id' => 'pro'])->plan_id)->toBe('pro');
});

it('entitles a paying user by the features of their plan', function () {
    $user = enforcedUser('entitled', [
        'plan_id' => 'pro',
        'subscription_ends_at' => Carbon::now()->addMonth(),
    ]);

    $this->actingAs($user)->get('/app/reports')->assertOk();
});

it('refuses a feature the user\'s plan does not carry', function () {
    $user = enforcedUser('unentitled', [
        'plan_id' => 'free',
        'subscription_ends_at' => Carbon::now()->addMonth(),
    ]);

    $this->actingAs($user)->getJson('/app/reports')->assertStatus(403);
});
