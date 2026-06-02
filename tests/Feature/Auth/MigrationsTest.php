<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the identity columns to the users table', function () {
    foreach ([
        'lastname', 'middlename', 'phone', 'phone_verified_at',
        'provider_id', 'provider_name', 'provider_token', 'provider_refresh_token',
        'avatar', 'country', 'locale', 'sex', 'birth_date', 'ui_settings',
        'is_admin', 'user_blocked_at', 'user_blocked_status', 'block_code',
        'user_deleted_at', 'last_login_at', 'last_activity_at',
    ] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeTrue("users.$column missing");
    }
});

it('makes the password column nullable for oauth-only users', function () {
    // A null password must insert without violating a NOT NULL constraint.
    DB::table('users')->insert([
        'name' => 'OAuth Only',
        'email' => 'oauth@x.test',
        'password' => null,
    ]);

    expect(DB::table('users')->where('email', 'oauth@x.test')->value('password'))->toBeNull();
});

it('creates the user_sessions table with the expected columns', function () {
    expect(Schema::hasTable('user_sessions'))->toBeTrue();

    foreach ([
        'id', 'user_id', 'session_id', 'login_method', 'ip_address', 'user_agent',
        'user_device_type', 'user_device_name', 'user_os', 'user_browser',
        'last_activity', 'last_route_name', 'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('user_sessions', $column))->toBeTrue("user_sessions.$column missing");
    }
});

it('enforces a unique (user_id, session_id) on user_sessions', function () {
    DB::table('users')->insert(['id' => 1, 'name' => 'A', 'email' => 'a@x.test', 'password' => 'x']);

    DB::table('user_sessions')->insert([
        'user_id' => 1, 'session_id' => 'dup', 'login_method' => 'native',
    ]);

    expect(fn () => DB::table('user_sessions')->insert([
        'user_id' => 1, 'session_id' => 'dup', 'login_method' => 'native',
    ]))->toThrow(QueryException::class);
});
