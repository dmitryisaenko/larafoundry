<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Seo\Support\ConfigOgImageResolver;
use Illuminate\Support\Facades\Storage;

/**
 * The default OG image resolver is a THIN, config-only seam (phase 5.2): an
 * absolute URL passes through, a relative path resolves via the media disk, and
 * an unset value yields null. No image processing.
 */
it('returns an absolute http(s) URL as-is', function () {
    config()->set('larafoundry-seo.og.default_image', 'https://cdn.test/og.png');

    expect((new ConfigOgImageResolver)->default())->toBe('https://cdn.test/og.png');
});

it('resolves a relative path via the media disk', function () {
    config()->set('larafoundry-media.disk', 'public');
    config()->set('larafoundry-seo.og.default_image', 'og/default.png');

    $expected = Storage::disk('public')->url('og/default.png');

    expect((new ConfigOgImageResolver)->default())->toBe($expected);
});

it('returns null when no image is configured', function () {
    config()->set('larafoundry-seo.og.default_image', null);

    expect((new ConfigOgImageResolver)->default())->toBeNull();
});

it('returns null for an empty string', function () {
    config()->set('larafoundry-seo.og.default_image', '');

    expect((new ConfigOgImageResolver)->default())->toBeNull();
});
