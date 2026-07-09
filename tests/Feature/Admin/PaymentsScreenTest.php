<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The Payments console screen (phase 7) is a stub over the billing seam: gated
 * like the rest of the console, it renders an empty state and flips its copy on
 * `billing_enabled`. There are no records to list until a gateway is wired.
 */

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry.security.super_admin.require_otp' => false]);
});

function payAdmin(): User
{
    return User::create([
        'name' => 'Boss', 'email' => 'pay-boss@x.test', 'password' => 'secret-pass',
        'email_verified_at' => now(), 'is_admin' => true,
    ]);
}

function payMember(): User
{
    return User::create([
        'name' => 'Joe', 'email' => 'pay-joe@x.test', 'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('forbids a non-admin from the payments screen', function () {
    $this->actingAs(payMember())->get('/admin/payments')->assertForbidden();
});

it('redirects a guest from the payments screen', function () {
    $this->get('/admin/payments')->assertRedirect();
});

it('renders the payments stub with billing disabled by default', function () {
    config(['larafoundry.billing.enabled' => false]);

    $this->actingAs(payAdmin())
        ->get('/admin/payments', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Payments/Index')
        ->assertJsonPath('props.billing_enabled', false);
});

it('reflects billing being enabled in the prop', function () {
    config(['larafoundry.billing.enabled' => true]);

    $this->actingAs(payAdmin())
        ->get('/admin/payments', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.billing_enabled', true);
});
