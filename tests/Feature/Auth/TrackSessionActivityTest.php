<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\TrackSessionActivity;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Auth\Support\DeviceFingerprint;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 * Session tracking is a per-request middleware (TrackSessionActivity), not a
 * Login-event listener: the Fortify pipeline regenerates the session id several
 * times, so only a per-request pass keyed to the FINAL id is reliable. These
 * tests therefore drive real authenticated requests through a tiny route that
 * carries the `larafoundry.session.track` alias, and assert the resulting
 * user_sessions row after the response (the middleware writes AFTER $next).
 */

/** A realistic desktop-chrome UA used across the request-level tests. */
const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

beforeEach(function () {
    // A named GET page and a JSON POST endpoint, both behind the tracker, so we
    // can assert page-visit vs XHR behaviour for last_route_name.
    Route::middleware(['web', 'larafoundry.session.track'])
        ->get('/_t/page', fn () => 'ok')
        ->name('test.page');

    Route::middleware(['web', 'larafoundry.session.track'])
        ->post('/_t/api', fn () => response()->json(['ok' => true]))
        ->name('test.api');
});

function trackUser(): User
{
    return User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
}

/**
 * Run TrackSessionActivity against a hand-built request sharing one started
 * session $store, so successive calls land on the SAME session id (a real test
 * client mints a fresh session per request, which can't model the per-request
 * dedup on a stable id). Mirrors the suite's SessionControllerTest pattern.
 */
function runTracker(User $user, Store $store, string $method = 'GET', ?string $routeName = null, bool $json = false): void
{
    $server = ['HTTP_USER_AGENT' => CHROME_UA, 'REMOTE_ADDR' => '203.0.113.7'];
    if ($json) {
        $server['HTTP_ACCEPT'] = 'application/json';
    }

    $request = Request::create('/_t/page', $method, server: $server);
    $request->setLaravelSession($store);

    if ($routeName !== null) {
        $route = (new Illuminate\Routing\Route([$method], '/_t/page', []))->name($routeName);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
    }

    // Bind first: the container's `request` rebinding resets the user resolver,
    // so set our resolver AFTER instancing to keep the authenticated subject.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    (new TrackSessionActivity(app(DeviceFingerprintResolver::class)))
        ->handle($request, fn (Request $r) => response('ok'));
}

/** A started ArraySession with a valid 40-char id derived from $label. */
function trackerSession(string $label): Store
{
    $id = str_pad(preg_replace('/[^a-zA-Z0-9]/', '', $label), 40, 'a');
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), $id);
    $store->start();

    return $store;
}

it('creates a tracked session row with device data on the first authenticated request', function () {
    $user = trackUser();

    $this->actingAs($user)
        ->withHeaders(['User-Agent' => CHROME_UA])
        ->get(route('test.page'))
        ->assertOk();

    $sessionId = session()->getId();

    $session = $user->sessions()->first();

    expect($session)->not->toBeNull()
        ->and($session->session_id)->toBe($sessionId)
        ->and($session->login_method)->toBe('native')
        ->and($session->user_device_type)->toBe('desktop')
        ->and($session->user_browser)->toBe('Chrome')
        ->and($session->user_os)->toBe('Windows 10/11');
});

it('stamps last_login_at / last_activity_at on the user on first sight', function () {
    $user = trackUser();

    expect($user->fresh()->last_login_at)->toBeNull();

    $this->actingAs($user)->get(route('test.page'))->assertOk();

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->fresh()->last_activity_at)->not->toBeNull();
});

it('does not duplicate the row across requests on the same session id', function () {
    $user = trackUser();
    $store = trackerSession('dedup');

    // First request: establishes the tracked row.
    runTracker($user, $store);
    expect($user->sessions()->count())->toBe(1);
    $firstActivity = $user->sessions()->first()->last_activity;

    // travel so the refreshed last_activity is observably newer.
    $this->travel(2)->seconds();

    // Second request on the SAME session id: refresh in place, no new row.
    runTracker($user, $store);

    expect($user->sessions()->count())->toBe(1)
        ->and($user->sessions()->first()->last_activity->gt($firstActivity))->toBeTrue();
});

it('captures the OAuth provider from the method hint, single-use', function () {
    $user = trackUser();

    $this->actingAs($user)
        ->withSession([TrackSessionActivity::METHOD_HINT => 'google'])
        ->get(route('test.page'))
        ->assertOk();

    expect($user->sessions()->first()->login_method)->toBe('google')
        // The hint is pulled, so it must not linger for the next request.
        ->and(session()->has(TrackSessionActivity::METHOD_HINT))->toBeFalse();
});

it('writes last_route_name for a named page GET', function () {
    $user = trackUser();

    $this->actingAs($user)->get(route('test.page'))->assertOk();

    expect($user->sessions()->first()->last_route_name)->toBe('test.page');
});

it('does not overwrite last_route_name on a JSON/POST request', function () {
    $user = trackUser();
    $store = trackerSession('routekeep');

    // A page visit records the route name on the shared session.
    runTracker($user, $store, routeName: 'test.page');
    expect($user->sessions()->first()->last_route_name)->toBe('test.page');

    // A subsequent XHR POST refreshes activity but must keep the last page
    // (currentRouteName() returns null for JSON, and recordSessionActivity
    // keeps the existing value when given null).
    runTracker($user, $store, method: 'POST', routeName: 'test.api', json: true);

    expect($user->sessions()->first()->last_route_name)->toBe('test.page');
});

it('is a no-op for a guest request', function () {
    $this->get(route('test.page'))->assertOk();

    expect(UserSession::count())->toBe(0);
});

it('writes the row directly through recordSessionActivity()', function () {
    // Exercise the trait writer in isolation of the middleware plumbing.
    $user = trackUser();

    // Bind a current request so request()->ip()/userAgent() resolve.
    $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.9',
        'HTTP_USER_AGENT' => CHROME_UA,
    ]);
    $this->actingAs($user);

    $device = new DeviceFingerprint(type: 'mobile', name: 'iPhone', os: 'iOS', browser: 'Safari');

    $user->recordSessionActivity('direct-session-id', $device, 'apple', 'some.route');

    $session = $user->sessions()->first();

    expect($session)->not->toBeNull()
        ->and($session->session_id)->toBe('direct-session-id')
        ->and($session->login_method)->toBe('apple')
        ->and($session->user_device_type)->toBe('mobile')
        ->and($session->user_os)->toBe('iOS')
        ->and($session->last_route_name)->toBe('some.route')
        ->and($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->fresh()->last_activity_at)->not->toBeNull();

    // Dedup + last_activity refresh: the same id updates in place.
    $firstActivity = $session->last_activity;
    $this->travel(2)->seconds();
    $user->recordSessionActivity('direct-session-id', $device);

    expect($user->sessions()->count())->toBe(1)
        ->and($user->sessions()->first()->last_activity->gt($firstActivity))->toBeTrue();
});

it('covers the post-registration / post-login path via the same per-request tracker', function () {
    // Registration and login both end in an authenticated session; tracking is
    // not tied to the Login event but to the first authenticated request after.
    // Driving an authenticated request through the tracker is therefore exactly
    // what the first page-load after register/login does — so register tracking
    // is covered by this same mechanism.
    $user = User::create(['name' => 'New', 'email' => 'new@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)->get(route('test.page'))->assertOk();

    expect($user->sessions()->count())->toBe(1)
        ->and($user->sessions()->first()->login_method)->toBe('native');
});
