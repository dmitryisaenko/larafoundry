<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataExportRegistry;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('wires the export registry with the core profile section', function () {
    $registry = app(UserDataExportRegistry::class);

    expect($registry)->toBeInstanceOf(UserDataExportRegistry::class)
        ->and($registry->providers())->not->toBeEmpty();
});

it('collects the user\'s own profile as the core export section', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret-pass', 'phone' => '+100']);

    $export = app(UserDataExportRegistry::class)->collect($user);

    expect($export)->toHaveKey('profile')
        ->and($export['profile']['name'])->toBe('Ada')
        ->and($export['profile']['email'])->toBe('ada@x.test')
        ->and($export['profile'])->not->toHaveKey('password');
});
