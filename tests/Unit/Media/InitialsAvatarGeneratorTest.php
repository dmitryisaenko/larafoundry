<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Media\Contracts\AvatarGenerator;
use Dmitryisaenko\LaraFoundry\Media\Support\InitialsAvatarGenerator;

/**
 * The default avatar generator renders initials inline as an SVG data URI — no
 * stored file, no GD dependency (phase 2.4).
 */
beforeEach(function () {
    $this->generator = app(InitialsAvatarGenerator::class);
});

it('binds the AvatarGenerator contract to the initials generator by default', function () {
    expect(app(AvatarGenerator::class))->toBeInstanceOf(InitialsAvatarGenerator::class);
});

it('returns a base64 svg data uri', function () {
    $url = $this->generator->url('Ada Lovelace');

    expect($url)->toStartWith('data:image/svg+xml;base64,');

    $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));
    expect($svg)->toContain('<svg');
});

it('is deterministic for the same seed', function () {
    expect($this->generator->url('Grace Hopper'))
        ->toBe($this->generator->url('Grace Hopper'));
});

it('still renders a placeholder for an empty name', function () {
    $url = $this->generator->url('   ');

    expect($url)->toStartWith('data:image/svg+xml;base64,');
});
