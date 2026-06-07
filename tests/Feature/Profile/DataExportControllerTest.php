<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication', function () {
    $this->get('/profile/data/export')->assertRedirect();
});

it('streams the user\'s data as a JSON attachment', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret-pass', 'phone' => '+100']);

    $response = $this->actingAs($user)->get('/profile/data/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/json')
        ->and($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->headers->get('content-disposition'))->toContain('my-data-'.$user->getKey());

    $payload = json_decode($response->streamedContent(), true);

    expect($payload)->toHaveKeys(['meta', 'data'])
        ->and($payload['meta']['user_id'])->toBe($user->getKey())
        ->and($payload['meta']['format'])->toBe('larafoundry.user-data-export.v1')
        ->and($payload['data']['profile']['email'])->toBe('ada@x.test')
        ->and($payload['data'])->toHaveKeys(['profile', 'sessions', 'ui_settings', 'consent', 'tickets', 'notifications'])
        ->and($payload['data']['profile'])->not->toHaveKey('password');
});

it('never leaks another user\'s data or the password hash', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret-pass']);
    User::create(['name' => 'Eve', 'email' => 'eve@x.test', 'password' => 'other-pass']);

    $body = $this->actingAs($user)->get('/profile/data/export')->streamedContent();

    expect($body)->not->toContain('eve@x.test')
        ->and($body)->not->toContain($user->getAuthPassword());
});

it('rate-limits repeated exports', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret-pass']);

    // Default throttle is 3 per day; the fourth pull in the window is refused.
    $this->actingAs($user)->get('/profile/data/export')->assertOk();
    $this->actingAs($user)->get('/profile/data/export')->assertOk();
    $this->actingAs($user)->get('/profile/data/export')->assertOk();
    $this->actingAs($user)->get('/profile/data/export')->assertStatus(429);
});
