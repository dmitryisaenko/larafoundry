<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Settings\Models\Setting;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The dismiss endpoint (phase 5.4): an authenticated user hides their checklist
 * for good; the flag is a user-scoped setting written by the endpoint (not the
 * self-service form, which rejects the form=false key). A guest cannot reach it.
 */
uses(RefreshDatabase::class);

it('persists the dismissed flag for the authenticated user and redirects back', function () {
    $user = rbacUser();

    $this->actingAs($user)
        ->from('/home')
        ->post('/onboarding/dismiss')
        ->assertRedirect('/home');

    // The flag is stored user-scoped, resolvable back through the repository.
    $row = Setting::query()
        ->where('scope', 'user')
        ->where('scope_id', (string) $user->id)
        ->where('key', 'onboarding.dismissed')
        ->first();

    expect($row)->not->toBeNull()
        ->and(app(SettingsRepository::class)->get('onboarding.dismissed', $user->id))->toBeTrue();
});

it('is idempotent — dismissing again keeps the flag true', function () {
    $user = rbacUser();

    $this->actingAs($user)->post('/onboarding/dismiss')->assertRedirect();
    $this->actingAs($user)->post('/onboarding/dismiss')->assertRedirect();

    expect(app(SettingsRepository::class)->get('onboarding.dismissed', $user->id))->toBeTrue();
});

it('redirects a guest to login', function () {
    $this->post('/onboarding/dismiss')->assertRedirect();

    expect(Setting::query()->where('key', 'onboarding.dismissed')->exists())->toBeFalse();
});
