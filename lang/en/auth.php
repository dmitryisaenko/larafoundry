<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry auth strings (namespace: larafoundry::auth)
|--------------------------------------------------------------------------
| Owned by the core so auth ships localised out of the box and follows the
| locale standard. Hosts override via `vendor:publish --tag=larafoundry-lang`.
*/

return [

    'verify_email' => [
        'subject' => 'Verify your email address',
        'intro' => 'Please confirm your email address by clicking the button below.',
        'action' => 'Verify email address',
        'outro' => 'If you did not create an account, no further action is required.',
    ],

    'oauth' => [
        'invalid_provider' => 'That sign-in provider is not available.',
        'redirect_failed' => 'Could not start sign-in. Please try again.',
        'callback_failed' => 'Sign-in was cancelled or failed. Please try again.',
        'no_email' => 'The provider did not share an email address, so we could not sign you in.',
        'email_taken' => 'An account with this email already exists. Please sign in with your password first, then link this provider from your settings.',
    ],

    'sessions' => [
        'others_logged_out' => 'You have been logged out of all other devices.',
    ],

    'account' => [
        'blocked' => 'Your account has been blocked. Please contact support.',
        'deleted' => 'This account is no longer available.',
    ],

    'password' => [
        'current_invalid' => 'The provided password does not match your current password.',
    ],

    'admin_alert' => [
        'subject' => 'Security alert: admin login attempt',
        'intro' => 'A :step login attempt was made on the admin account.',
        'ip' => 'IP address: :ip',
        'agent' => 'User agent: :agent',
        'outro' => 'If this was not you, review your account security immediately.',
    ],

];
