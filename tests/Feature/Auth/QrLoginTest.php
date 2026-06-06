<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity;
use Dmitryisaenko\LaraFoundry\Auth\Qr\Models\SignInRequest;
use Dmitryisaenko\LaraFoundry\Auth\Qr\Support\QrCodeGenerator;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The QR verify URL embeds the plaintext token. We never persist the plaintext,
 * so the test reads it straight from the URL the controller builds — exactly
 * what the QR image carries to the scanning device.
 */
beforeEach(function () {
    // Fake the QR renderer so tests don't depend on SVG output, and capture the
    // URL the controller encoded so we can drive the verify step from it.
    $this->app->bind(QrCodeGenerator::class, function () {
        return new class extends QrCodeGenerator
        {
            public function svgBase64(string $payload, int $size = 400): string
            {
                $GLOBALS['__qr_payload'] = $payload;

                return 'fake-svg';
            }
        };
    });
});

/**
 * Parse the {id, token} out of the captured verify URL.
 *
 * @return array{0: int, 1: string}
 */
function qrIdAndToken(): array
{
    $url = $GLOBALS['__qr_payload'] ?? '';
    preg_match('#/larafoundry/qr/verify/(\d+)/([^/?]+)#', $url, $m);

    return [(int) ($m[1] ?? 0), $m[2] ?? ''];
}

it('completes the full cross-device handshake', function () {
    // 1. Guest (the web browser) generates a QR.
    $this->postJson(route('larafoundry.qr.generate'))
        ->assertOk()
        ->assertJsonStructure(['qrCode', 'activeUntil']);

    [$id, $token] = qrIdAndToken();
    expect($id)->toBeGreaterThan(0)->and($token)->not->toBe('');

    // 2. The already-authenticated device approves it (Sanctum-guarded).
    $approver = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    Sanctum::actingAs($approver);

    $this->getJson("/larafoundry/qr/verify/{$id}/{$token}")->assertOk();

    expect(SignInRequest::find($id)->approved)->toBeTrue();

    // Drop the Sanctum actor: the poll happens on the OTHER device (the guest
    // browser), which is not authenticated. forgetGuards clears the resolved
    // user so the `guest` middleware on the poll route sees a guest again.
    $this->app['auth']->forgetGuards();

    // 3. Back on the guest browser, polling now logs the approver in. The poll
    //    side uses the web guard (Auth::login), independent of the Sanctum guard
    //    the approver used above, so a true web-session login is what we assert.
    $this->withSession(['larafoundry_qr_id' => $id])
        ->postJson(route('larafoundry.qr.poll'))
        ->assertOk()
        ->assertJson(['result' => true]);

    $this->assertAuthenticatedAs($approver->fresh(), 'web');

    // The request is consumed (single-use).
    expect(SignInRequest::find($id))->toBeNull();
});

it('stores the token hashed, never in plaintext', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();

    [$id, $token] = qrIdAndToken();

    $row = SignInRequest::find($id);

    expect($row->token)->toBe(hash('sha256', $token))
        ->and($row->token)->not->toBe($token);
});

it('rejects an expired code at verify time', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id, $token] = qrIdAndToken();

    SignInRequest::find($id)->forceFill(['expires_at' => now()->subMinute()])->save();

    $approver = User::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass']);
    Sanctum::actingAs($approver);

    $this->getJson("/larafoundry/qr/verify/{$id}/{$token}")->assertNotFound();
    expect(SignInRequest::find($id)->approved)->toBeFalse();
});

it('never slides expiry past the absolute cap', function () {
    config()->set('larafoundry.auth.qr.ttl_minutes', 5);
    config()->set('larafoundry.auth.qr.absolute_ttl_minutes', 15);

    // First generate creates the row and seats the session secret.
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id, $token] = qrIdAndToken();

    $row = SignInRequest::find($id);
    $createdAt = $row->created_at;

    // Travel near the cap and regenerate (re-use path slides expiry).
    $this->travelTo($createdAt->copy()->addMinutes(14));
    $this->withSession([
        'larafoundry_qr_id' => $id,
        'larafoundry_qr_secret' => $token,
    ])->postJson(route('larafoundry.qr.generate'))->assertOk();

    $capped = SignInRequest::find($id)->expires_at;

    // 14 + 5 = 19 would exceed the 15-minute cap → clamped to created_at + 15.
    expect($capped->lessThanOrEqualTo($createdAt->copy()->addMinutes(15)))->toBeTrue();

    $this->travelBack();
});

it('blocks a super-admin from approving', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id, $token] = qrIdAndToken();

    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');
    $admin = User::create(['name' => 'Boss', 'email' => 'boss@x.test', 'password' => 'secret-pass']);
    $admin->forceFill(['is_admin' => true])->save();
    Sanctum::actingAs($admin);

    $this->getJson("/larafoundry/qr/verify/{$id}/{$token}")->assertForbidden();
    expect(SignInRequest::find($id)->approved)->toBeFalse();
});

it('returns a clean 404 for a bogus token, never a 500', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id] = qrIdAndToken();

    $approver = User::create(['name' => 'C', 'email' => 'c@x.test', 'password' => 'secret-pass']);
    Sanctum::actingAs($approver);

    $this->getJson("/larafoundry/qr/verify/{$id}/not-the-real-token")->assertNotFound();
});

it('requires authentication to verify', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id, $token] = qrIdAndToken();

    $this->getJson("/larafoundry/qr/verify/{$id}/{$token}")->assertUnauthorized();
});

it('verify works via a Bearer token too (future mobile path)', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id, $token] = qrIdAndToken();

    $approver = User::create(['name' => 'D', 'email' => 'd@x.test', 'password' => 'secret-pass']);
    $bearer = $approver->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$bearer)
        ->getJson("/larafoundry/qr/verify/{$id}/{$token}")
        ->assertOk();

    expect(SignInRequest::find($id)->approved)->toBeTrue();
});

it('does not log in while the request is still unapproved', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id] = qrIdAndToken();

    $this->withSession(['larafoundry_qr_id' => $id])
        ->postJson(route('larafoundry.qr.poll'))
        ->assertOk()
        ->assertJson(['result' => false]);

    $this->assertGuest();
});

it('never writes the plaintext token to the activity log url', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id, $token] = qrIdAndToken();

    $approver = User::create(['name' => 'E', 'email' => 'e@x.test', 'password' => 'secret-pass']);
    Sanctum::actingAs($approver);

    $this->getJson("/larafoundry/qr/verify/{$id}/{$token}")->assertOk();

    $urls = Activity::query()->pluck('full_url');

    foreach ($urls as $url) {
        expect($url)->not->toContain($token);
    }
    // And the redaction actually fired (the verify row recorded a redacted path).
    expect($urls->filter(fn ($u) => str_contains((string) $u, '[redacted]'))->isNotEmpty())->toBeTrue();
});

it('records the approver as the audit causer on the Bearer path', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id, $token] = qrIdAndToken();

    $approver = User::create(['name' => 'F', 'email' => 'f@x.test', 'password' => 'secret-pass']);
    $bearer = $approver->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$bearer)
        ->getJson("/larafoundry/qr/verify/{$id}/{$token}")
        ->assertOk();

    $approved = Activity::query()
        ->where('description', 'qr.approved')
        ->first();

    expect($approved)->not->toBeNull()
        ->and((int) $approved->causer_id)->toBe((int) $approver->getKey());
});

it('rejects a blocked approver without leaking the reason', function () {
    $this->postJson(route('larafoundry.qr.generate'))->assertOk();
    [$id, $token] = qrIdAndToken();

    $blocked = User::create(['name' => 'G', 'email' => 'g@x.test', 'password' => 'secret-pass']);
    $blocked->forceFill(['user_blocked_at' => now()])->save();
    Sanctum::actingAs($blocked);

    $this->getJson("/larafoundry/qr/verify/{$id}/{$token}")->assertForbidden();
    expect(SignInRequest::find($id)->approved)->toBeFalse();
});

it('registers QR routes only when the feature is enabled', function () {
    // Routes register at boot, so we exercise the guard by re-running the route
    // files into a fresh router for each flag value — the same condition the
    // provider's loadRoutesFrom evaluates at boot. Counting QR routes by URI on a
    // fresh router proves the flag actually drives registration (not a trivially
    // empty router): enabled => present, disabled => absent.
    $qrRouteCount = function (bool $enabled): int {
        config()->set('larafoundry.auth.qr.enabled', $enabled);

        $router = new Router(app('events'), app());
        Route::swap($router);

        require dirname(__DIR__, 3).'/routes/qr.php';
        require dirname(__DIR__, 3).'/routes/api.php';

        return collect($router->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'larafoundry/qr'))
            ->count();
    };

    expect($qrRouteCount(true))->toBe(3)   // generate, poll, verify
        ->and($qrRouteCount(false))->toBe(0);
});

it('prunes expired and stale requests, keeping live ones', function () {
    $live = SignInRequest::create(['token' => hash('sha256', 'a'), 'expires_at' => now()->addMinutes(5)]);
    $expired = SignInRequest::create(['token' => hash('sha256', 'b'), 'expires_at' => now()->subMinute()]);
    $stale = SignInRequest::create(['token' => hash('sha256', 'c'), 'expires_at' => now()->addMinutes(5)]);
    $stale->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('larafoundry:qr:prune')->assertSuccessful();

    expect(SignInRequest::find($live->id))->not->toBeNull()
        ->and(SignInRequest::find($expired->id))->toBeNull()
        ->and(SignInRequest::find($stale->id))->toBeNull();
});
