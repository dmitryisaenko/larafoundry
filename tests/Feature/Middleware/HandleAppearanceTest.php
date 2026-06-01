<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleAppearance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

function appearanceNext(): Closure
{
    return fn (Request $request): Response => new Response('ok');
}

afterEach(function () {
    View::flushFinderCache();
});

it('shares a valid appearance cookie with views', function () {
    $request = Request::create('/', 'GET', cookies: ['appearance' => 'dark']);

    (new HandleAppearance)->handle($request, appearanceNext());

    expect(View::shared('appearance'))->toBe('dark');
});

it('defaults to system when the cookie is missing', function () {
    $request = Request::create('/', 'GET');

    (new HandleAppearance)->handle($request, appearanceNext());

    expect(View::shared('appearance'))->toBe('system');
});

it('rejects an unexpected cookie value and defaults to system', function () {
    $request = Request::create('/', 'GET', cookies: ['appearance' => 'rainbow']);

    (new HandleAppearance)->handle($request, appearanceNext());

    expect(View::shared('appearance'))->toBe('system');
});
