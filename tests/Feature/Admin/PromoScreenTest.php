<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The Promo codes console screen (phase 4) is a monetization stub: gated like the
 * rest of the console, it renders an empty state that reserves the slot for the
 * paid billing add-on and, while billing is off, exposes an upsell URL.
 */

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry.security.super_admin.require_otp' => false]);
});

function promoAdmin(): User
{
    return User::create([
        'name' => 'Boss', 'email' => 'promo-boss@x.test', 'password' => 'secret-pass',
        'email_verified_at' => now(), 'is_admin' => true,
    ]);
}

function promoMember(): User
{
    return User::create([
        'name' => 'Joe', 'email' => 'promo-joe@x.test', 'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('forbids a non-admin from the promo screen', function () {
    $this->actingAs(promoMember())->get('/admin/promo')->assertForbidden();
});

it('redirects a guest from the promo screen', function () {
    $this->get('/admin/promo')->assertRedirect();
});

it('renders the promo stub with billing disabled by default', function () {
    config(['larafoundry.billing.enabled' => false]);
    config(['larafoundry.upsell.billing_url' => 'https://larafoundry.com']);

    $this->actingAs(promoAdmin())
        ->get('/admin/promo', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Promo/Index')
        ->assertJsonPath('props.billing_enabled', false)
        ->assertJsonPath('props.upsell_url', 'https://larafoundry.com');
});

it('reflects billing being enabled in the prop', function () {
    config(['larafoundry.billing.enabled' => true]);

    $this->actingAs(promoAdmin())
        ->get('/admin/promo', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.billing_enabled', true);
});
