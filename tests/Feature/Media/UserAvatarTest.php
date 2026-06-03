<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Admin\Http\Resources\AdminUserResource;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Phase 2.4: User::avatar_url always resolves, handling the column's three
 * shapes (recon finding #5): empty -> generated initials, an absolute URL -> as
 * is, a stored path -> through the media disk.
 */

function avatarUser(?string $avatar): User
{
    return User::create([
        'name' => 'Ada',
        'lastname' => 'Lovelace',
        'email' => 'ada'.uniqid().'@x.test',
        'password' => 'secret-pass',
        'avatar' => $avatar,
    ]);
}

it('generates an initials placeholder when the user has no avatar', function () {
    expect(avatarUser(null)->avatar_url)->toStartWith('data:image/svg+xml;base64,');
});

it('returns an external oauth avatar url unchanged', function () {
    $url = 'https://lh3.googleusercontent.com/a/ada.jpg';

    expect(avatarUser($url)->avatar_url)->toBe($url);
});

it('resolves a stored avatar path through the media disk', function () {
    Storage::fake('public');

    $user = avatarUser('avatars/2026/06/abc.jpg');

    expect($user->avatar_url)->toContain('avatars/2026/06/abc.jpg')
        ->and($user->avatar_url)->not->toStartWith('data:');
});

it('serialises avatar_url in the admin user resource', function () {
    $resource = (new AdminUserResource(
        avatarUser(null)
    ))->toArray(request());

    expect($resource)->toHaveKey('avatar_url')
        ->and($resource['avatar_url'])->toStartWith('data:image/svg+xml;base64,');
});
