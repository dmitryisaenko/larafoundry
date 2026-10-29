<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataExportRegistry;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataPurgeRegistry;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ceUser(string $email): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('exports the user\'s own wallet and movements', function () {
    $user = ceUser('a@x.test');
    app(CreditService::class)->credit($user, 40, 'purchase');
    app(CreditService::class)->debit($user, 15, 'usage_lookup');

    $export = app(UserDataExportRegistry::class)->collect($user);

    expect($export['credits']['balance'])->toBe(25)
        ->and(array_column($export['credits']['movements'], 'amount'))->toBe([40, -15])
        ->and($export['credits']['movements'][1]['reason'])->toBe('usage_lookup');
});

it('exports the host\'s free payload too', function () {
    $user = ceUser('m@x.test');
    app(CreditService::class)->credit($user, 5, 'gift', meta: ['document' => 'My diary']);

    $export = app(UserDataExportRegistry::class)->collect($user);

    expect($export['credits']['movements'][0]['meta'])->toBe(['document' => 'My diary']);
});

it('erases the free text of the user\'s ledger on deletion and keeps the money rows', function () {
    $user = ceUser('d@x.test');
    $other = ceUser('o@x.test');
    app(CreditService::class)->credit($user, 40, 'purchase', note: 'for ivan@mail.test', meta: ['document' => 'My diary']);
    app(CreditService::class)->credit($other, 7, 'purchase', note: 'keep me', meta: ['k' => 'v']);

    app(UserDataPurgeRegistry::class)->purge($user);

    $mine = CreditTransaction::query()->whereHas('wallet', fn ($w) => $w->where('owner_id', $user->id))->sole();
    $theirs = CreditTransaction::query()->whereHas('wallet', fn ($w) => $w->where('owner_id', $other->id))->sole();

    expect($mine->note)->toBeNull()
        ->and($mine->meta)->toBeNull()
        ->and($mine->amount)->toBe(40)
        ->and(app(CreditService::class)->balance($user))->toBe(40)
        ->and($theirs->note)->toBe('keep me')
        ->and($theirs->meta)->toBe(['k' => 'v']);
});

it('exports nothing for a user without a wallet', function () {
    $user = ceUser('b@x.test');
    app(CreditService::class)->credit(ceUser('c@x.test'), 40, 'purchase');

    $export = app(UserDataExportRegistry::class)->collect($user);

    expect($export['credits'] ?? [])->toBe([]);
});
