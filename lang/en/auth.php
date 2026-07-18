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

    'reset_password' => [
        'subject' => 'Reset your password',
        'intro' => 'You are receiving this email because we received a password reset request for your account.',
        'action' => 'Reset password',
        'outro' => 'If you did not request a password reset, no further action is required.',
    ],

    'welcome' => [
        'subject' => 'Welcome to :app',
        'intro' => 'Welcome to :app. Your account is ready — sign in to get started.',
        'action' => 'Sign in',
        'outro' => 'If you need help, just reply to this email.',
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

    'super_admin' => [
        'email_reserved' => 'This email address is reserved and cannot be registered.',
    ],

    'admin_otp' => [
        'setup_required' => 'Two-factor authentication is required for the operator console. Set it up first.',
        'invalid_code' => 'The provided authentication code was invalid.',
    ],

    'operator_security' => [
        'two_factor_enabled' => 'Two-factor authentication is enabled.',
        'two_factor_disabled' => 'Two-factor authentication is disabled.',
        'recovery_codes_regenerated' => 'Recovery codes regenerated.',
        'password_updated' => 'Your password has been updated.',
    ],

    'pin' => [
        'enabled' => 'PIN lock enabled.',
        'disabled' => 'PIN lock disabled.',
        'invalid' => 'Incorrect PIN.',
        'locked_out' => 'Too many attempts. Try again in :minutes minute(s).',
        'locked_change' => 'Unlock this session before changing your PIN.',
    ],

    'qr' => [
        'invalid' => 'This sign-in code is invalid or has expired.',
        'approved' => 'Sign-in approved. You can return to the other device.',
        'admin_forbidden' => 'Administrators cannot sign in by QR code.',
    ],

    'magic_link' => [
        'subject' => 'Your sign-in link for :app',
        'intro' => 'Click the button below to sign in. This link works once and expires in :minutes minutes.',
        'action' => 'Sign in',
        'outro' => 'If you did not request this, you can safely ignore this email.',
        'sent' => 'If that email address can receive mail, a sign-in link is on its way. Please check your inbox.',
        'invalid' => 'This sign-in link is invalid or has expired. Please request a new one.',
    ],

    'admin_alert' => [
        'subject' => 'Security alert: admin access attempt',
        'intro' => 'A failed :step attempt was made on the admin account.',
        'ip' => 'IP address: :ip',
        'agent' => 'User agent: :agent',
        'device' => 'Device: :device',
        'outro' => 'If this was not you, review your account security immediately.',
        'step' => [
            'password' => 'password sign-in',
            'lockout' => 'sign-in (too many attempts, locked out)',
            'admin_otp' => 'operator-console two-factor',
            'pin' => 'session PIN',
        ],
    ],

];
