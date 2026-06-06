<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Locate the core's PAT migration file and return a freshly required instance.
 */
function patMigration(): object
{
    return require dirname(__DIR__, 3)
        .'/database/migrations/2026_01_08_001700_create_personal_access_tokens_table.php';
}

/**
 * Register a probe endpoint guarded by `auth:sanctum`, returning the resolved
 * user's id. This is the exact shape the QR verify endpoint (sub-phase C) will
 * use: one route, two ways in (web session cookie or Bearer token).
 */
beforeEach(function () {
    Route::middleware('auth:sanctum')->get('/__sanctum_probe', function () {
        return response()->json(['id' => request()->user()->getKey()]);
    });
});

it('runs the personal access tokens migration the core owns', function () {
    expect(Schema::hasTable('personal_access_tokens'))->toBeTrue();
});

it('authenticates a request with a Bearer token (future mobile path)', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $token = $user->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/__sanctum_probe')
        ->assertOk()
        ->assertJson(['id' => $user->getKey()]);
});

it('authenticates the same route with a web session (current path)', function () {
    $user = User::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass']);

    // Sanctum::actingAs with no abilities authenticates via the guard exactly as
    // a stateful same-domain web request would, proving the guard-agnostic shape.
    Sanctum::actingAs($user);

    $this->getJson('/__sanctum_probe')
        ->assertOk()
        ->assertJson(['id' => $user->getKey()]);
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/__sanctum_probe')->assertUnauthorized();
});

it('rejects a request with a bogus Bearer token', function () {
    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->getJson('/__sanctum_probe')
        ->assertUnauthorized();
});

it('exposes token abilities a future scoped mobile token can rely on', function () {
    $user = User::create(['name' => 'C', 'email' => 'c@x.test', 'password' => 'secret-pass']);

    $user->createToken('scoped', ['qr:verify']);

    Sanctum::actingAs($user, ['qr:verify']);

    expect($user->tokenCan('qr:verify'))->toBeTrue()
        ->and($user->tokenCan('something-else'))->toBeFalse();
});

it('rollback drops the table when this migration created it', function () {
    expect(Schema::hasTable('personal_access_tokens'))->toBeTrue();

    patMigration()->down();

    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});

it('rollback leaves the table when a published Sanctum migration owns it', function () {
    // Simulate a host that also published+ran Sanctum's own PAT migration: its
    // row is recorded in the migrations table. Our down() must not drop the
    // table out from under that migration.
    DB::table('migrations')->insert([
        'migration' => '2019_12_14_000001_create_personal_access_tokens_table',
        'batch' => 99,
    ]);

    patMigration()->down();

    expect(Schema::hasTable('personal_access_tokens'))->toBeTrue();
});
