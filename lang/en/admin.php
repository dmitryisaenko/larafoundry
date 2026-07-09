<?php

declare(strict_types=1);

/*
 * Server-side strings for the super-admin operator console (phase 2.3).
 *
 * Flash messages returned by the user-management and impersonation endpoints.
 * Frontend UI labels live in the English-as-key frontend dictionary
 * (lang/frontend/*.json), translated by vue-i18n — these are the server flashes.
 */

return [
    'users' => [
        'created' => 'User created.',
        'updated' => 'User updated.',
        'blocked' => 'User blocked.',
        'unblocked' => 'User unblocked.',
        'deleted' => 'User deleted.',
        'restored' => 'User restored.',
        'email_verified' => 'Email marked as verified.',
        'email_unverified' => 'Email verification cleared.',
        'phone_verified' => 'Phone marked as verified.',
        'phone_unverified' => 'Phone verification cleared.',
    ],

    'companies' => [
        'blocked' => 'Company blocked.',
        'unblocked' => 'Company unblocked.',
    ],

    'impersonate' => [
        'started' => 'You are now impersonating this user.',
        'stopped' => 'You have stopped impersonating.',
        'forbidden' => 'You are not allowed to impersonate this user.',
    ],
];
