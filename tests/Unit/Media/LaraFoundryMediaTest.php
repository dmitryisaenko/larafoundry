<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Media\LaraFoundryMedia;
use Illuminate\Support\Facades\Storage;

/**
 * LaraFoundryMedia decides "external URL vs stored path vs generated default"
 * in one place, so the avatar column's three shapes (recon finding #5) are
 * handled consistently (phase 2.4).
 */
beforeEach(function () {
    Storage::fake('public');
});

it('generates an initials placeholder when no avatar is stored', function () {
    expect(LaraFoundryMedia::avatarUrl(null, 'Ada Lovelace'))
        ->toStartWith('data:image/svg+xml;base64,');

    expect(LaraFoundryMedia::avatarUrl('   ', 'Ada Lovelace'))
        ->toStartWith('data:image/svg+xml;base64,');
});

it('returns an external avatar url as-is (does not re-prefix the disk url)', function () {
    $external = 'https://cdn.example.com/avatars/ada.png';

    expect(LaraFoundryMedia::avatarUrl($external, 'Ada'))->toBe($external);
});

it('resolves a stored relative path through the media disk', function () {
    $url = LaraFoundryMedia::avatarUrl('avatars/2026/06/abc.jpg', 'Ada');

    expect($url)->toContain('avatars/2026/06/abc.jpg')
        ->and($url)->not->toStartWith('data:');
});

it('returns null for an empty company logo and a url for a stored one', function () {
    expect(LaraFoundryMedia::logoUrl(null))->toBeNull();
    expect(LaraFoundryMedia::logoUrl(''))->toBeNull();
    expect(LaraFoundryMedia::logoUrl('company-logos/2026/06/x.jpg'))
        ->toContain('company-logos/2026/06/x.jpg');
});

it('passes through an external logo url unchanged', function () {
    expect(LaraFoundryMedia::logoUrl('https://cdn.example.com/logo.png'))
        ->toBe('https://cdn.example.com/logo.png');
});
