<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;

/**
 * Resolve the `auth_oauth` shared prop the way Inertia does: the value in
 * share() is a closure, so we call it. This prop is the single source the
 * frontend reads to render OAuth buttons, so it must mirror config exactly.
 *
 * @return array{enabled: bool, providers: array<int, string>}
 */
function resolveAuthOAuth(): array
{
    $shared = (new HandleInertiaRequests)->share(Request::create('/', 'GET'));

    $value = $shared['auth_oauth'];

    return is_callable($value) ? $value() : $value;
}

it('exposes the configured oauth enabled flag and provider list', function () {
    config()->set('larafoundry.auth.oauth.enabled', true);
    config()->set('larafoundry.auth.oauth.providers', ['google', 'facebook', 'twitter']);

    expect(resolveAuthOAuth())->toBe([
        'enabled' => true,
        'providers' => ['google', 'facebook', 'twitter'],
    ]);
});

it('reports oauth disabled when the master switch is off', function () {
    config()->set('larafoundry.auth.oauth.enabled', false);
    config()->set('larafoundry.auth.oauth.providers', ['google']);

    $prop = resolveAuthOAuth();

    expect($prop['enabled'])->toBeFalse()
        ->and($prop['providers'])->toBe(['google']);
});

it('filters non-string provider entries so a host typo degrades one entry', function () {
    config()->set('larafoundry.auth.oauth.enabled', true);
    config()->set('larafoundry.auth.oauth.providers', ['google', 123, null, 'twitter']);

    expect(resolveAuthOAuth()['providers'])->toBe(['google', 'twitter']);
});

it('returns an empty provider list when none are configured', function () {
    config()->set('larafoundry.auth.oauth.enabled', true);
    config()->set('larafoundry.auth.oauth.providers', []);

    expect(resolveAuthOAuth()['providers'])->toBe([]);
});

it('ships google/facebook/twitter as the packaged default provider set', function () {
    // Read the package's own config file (the source of truth that mergeConfigFrom
    // ships), not the resolved runtime value: the testbench skeleton keeps a
    // published copy that can lag behind a freshly edited default.
    $config = require __DIR__.'/../../../config/larafoundry.php';

    expect($config['auth']['oauth']['providers'])->toBe(['google', 'facebook', 'twitter']);
});
