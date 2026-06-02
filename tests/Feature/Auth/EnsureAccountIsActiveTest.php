<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\EnsureAccountIsActive;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * A request with a started session and the given user (or guest) resolved,
 * wired so the middleware's logout/invalidate path has a session to act on.
 */
function activeRequest(?User $user, bool $json = false): Request
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), str_pad('s', 40, 'a'));
    $store->start();

    $server = $json ? ['HTTP_ACCEPT' => 'application/json'] : [];
    $request = Request::create('/dashboard', 'GET', server: $server);
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function runActiveCheck(Request $request): mixed
{
    return (new EnsureAccountIsActive(app(VisitorStatus::class)))
        ->handle($request, fn (Request $r) => response('passed'));
}

it('passes a healthy authenticated user through', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    Auth::login($user);

    $response = runActiveCheck(activeRequest($user));

    expect($response->getContent())->toBe('passed')
        ->and(Auth::check())->toBeTrue();
});

it('passes a guest through untouched', function () {
    $response = runActiveCheck(activeRequest(null));

    expect($response->getContent())->toBe('passed');
});

it('logs out and redirects a blocked user', function () {
    $user = User::create([
        'name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass',
        'user_blocked_at' => now(),
    ]);
    Auth::login($user);

    $response = runActiveCheck(activeRequest($user));

    expect($response->isRedirect())->toBeTrue()
        ->and(Auth::check())->toBeFalse();
});

it('logs out and redirects a deleted user', function () {
    $user = User::create([
        'name' => 'D', 'email' => 'd@x.test', 'password' => 'secret-pass',
        'user_deleted_at' => now(),
    ]);
    Auth::login($user);

    $response = runActiveCheck(activeRequest($user));

    expect($response->isRedirect())->toBeTrue()
        ->and(Auth::check())->toBeFalse();
});

it('aborts 403 for a blocked user on a JSON request', function () {
    $user = User::create([
        'name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass',
        'user_blocked_at' => now(),
    ]);
    Auth::login($user);

    expect(fn () => runActiveCheck(activeRequest($user, json: true)))
        ->toThrow(HttpException::class);

    expect(Auth::check())->toBeFalse();
});

it('redirects a blocked user to the configured blocked route when it exists', function () {
    Route::get('/blocked-screen', fn () => 'blocked')->name('larafoundry.test.blocked');
    // Force the router to rebuild its name lookup so app('router')->has() (used
    // by the middleware) sees this runtime-registered route.
    Route::getRoutes()->refreshNameLookups();
    config()->set('larafoundry.auth.blocked_redirect_route', 'larafoundry.test.blocked');

    $user = User::create([
        'name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass',
        'user_blocked_at' => now(),
    ]);
    Auth::login($user);

    $response = runActiveCheck(activeRequest($user));

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toContain('/blocked-screen');
});
