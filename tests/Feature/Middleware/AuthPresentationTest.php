<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;

/**
 * Resolve the `auth_presentation` shared prop the way Inertia does: the value in
 * share() is a closure, so we call it.
 */
function resolveAuthPresentation(): string
{
    $shared = (new HandleInertiaRequests)->share(Request::create('/', 'GET'));

    $value = $shared['auth_presentation'];

    return is_callable($value) ? $value() : $value;
}

it('defaults the auth presentation to page', function () {
    config()->set('larafoundry.auth.presentation', null);

    expect(resolveAuthPresentation())->toBe('page');
});

it('exposes the configured modal presentation', function () {
    config()->set('larafoundry.auth.presentation', 'modal');

    expect(resolveAuthPresentation())->toBe('modal');
});

it('exposes the configured page presentation', function () {
    config()->set('larafoundry.auth.presentation', 'page');

    expect(resolveAuthPresentation())->toBe('page');
});

it('falls back to page for an unknown presentation value', function () {
    config()->set('larafoundry.auth.presentation', 'carousel');

    expect(resolveAuthPresentation())->toBe('page');
});

it('ships page as the packaged config default', function () {
    expect(config('larafoundry.auth.presentation'))->toBe('page');
});
