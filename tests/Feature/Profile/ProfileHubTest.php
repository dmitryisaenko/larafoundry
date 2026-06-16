<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the profile hub with the folded user-scope account settings', function () {
    config()->set('larafoundry.settings', [
        'email_notifications' => [
            'scope' => 'user',
            'label' => 'Email notifications',
            'type' => 'boolean',
            'default' => true,
            'validation' => ['boolean'],
        ],
        // form=false consent key — must NOT leak into the hub's form or values.
        'cookie_consent' => [
            'scope' => 'user',
            'type' => 'boolean',
            'default' => false,
            'validation' => ['boolean'],
            'form' => false,
        ],
        // a different scope — must not appear in the user-scope form at all.
        'support_email' => [
            'scope' => 'app',
            'type' => 'string',
            'default' => null,
            'validation' => ['nullable', 'email'],
        ],
    ]);

    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $response = $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->get('/profile')
        ->assertOk()
        ->assertJsonPath('component', 'Profile/ProfileHub');

    $schemaKeys = array_column($response->json('props.accountSettings.schema'), 'key');
    expect($schemaKeys)
        ->toContain('email_notifications')
        ->not->toContain('cookie_consent')   // form=false
        ->not->toContain('support_email');   // wrong scope

    // Values are limited to the form-visible keys — the consent flag is never shipped.
    $values = $response->json('props.accountSettings.values');
    expect($values)
        ->toHaveKey('email_notifications')
        ->not->toHaveKey('cookie_consent');
});
