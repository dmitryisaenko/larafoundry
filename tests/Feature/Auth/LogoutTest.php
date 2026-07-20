<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Contract guard for the endpoint the core's native <LogoutForm> posts to.
 *
 * The core logs out via a real form POST (full-page navigation) rather than an
 * Inertia visit, so the browser follows the redirect to the guest landing and
 * Inertia never pops its error-modal overlay on a non-Inertia landing. That
 * whole approach rests on POST /logout being a plain session-clearing redirect —
 * this test pins that behaviour so a Fortify/route change cannot silently break
 * the native form.
 */
it('logs the authenticated user out and redirects on POST /logout', function () {
    $user = User::create([
        'name' => 'Logout Tester',
        'email' => 'logout@x.test',
        'password' => 'secret-pass',
    ]);

    $this->actingAs($user);
    $this->assertAuthenticated();

    $this->post('/logout')->assertRedirect();

    $this->assertGuest();
});
