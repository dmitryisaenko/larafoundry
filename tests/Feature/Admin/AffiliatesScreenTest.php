<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The Affiliates console screen (phase 4) is a monetization stub: gated like the
 * rest of the console, it renders an empty state that reserves the slot for the
 * paid billing add-on and, while billing is off, exposes an upsell URL.
 */

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry.security.super_admin.require_otp' => false]);
});

function affAdmin(): User
{
    return User::create([
        'name' => 'Boss', 'email' => 'aff-boss@x.test', 'password' => 'secret-pass',
        'email_verified_at' => now(), 'is_admin' => true,
    ]);
}

function affMember(): User
{
    return User::create([
        'name' => 'Joe', 'email' => 'aff-joe@x.test', 'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('forbids a non-admin from the affiliates screen', function () {
    $this->actingAs(affMember())->get('/admin/affiliates')->assertForbidden();
});

it('redirects a guest from the affiliates screen', function () {
    $this->get('/admin/affiliates')->assertRedirect();
});

it('renders the affiliates stub with billing disabled by default', function () {
    config(['larafoundry.billing.enabled' => false]);
    config(['larafoundry.upsell.billing_url' => 'https://larafoundry.com']);

    $this->actingAs(affAdmin())
        ->get('/admin/affiliates', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Affiliates/Index')
        ->assertJsonPath('props.billing_enabled', false)
        ->assertJsonPath('props.upsell_url', 'https://larafoundry.com');
});

it('reflects billing being enabled in the prop', function () {
    config(['larafoundry.billing.enabled' => true]);

    $this->actingAs(affAdmin())
        ->get('/admin/affiliates', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.billing_enabled', true);
});
