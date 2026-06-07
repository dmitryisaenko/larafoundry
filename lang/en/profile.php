<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry profile strings (namespace: larafoundry::profile)
|--------------------------------------------------------------------------
| Server-side flash and validation messages for the profile hub (phase 5.1).
| Frontend labels are translated by vue-i18n (English-as-key); these are the
| backend strings only. Hosts override via `vendor:publish --tag=larafoundry-lang`.
*/

return [

    'updated' => 'Your profile has been updated.',

    'email' => [
        'changed' => 'Your email address was changed. Check your inbox to verify the new address — other devices have been signed out.',
    ],

    'avatar' => [
        'updated' => 'Your photo has been updated.',
        'removed' => 'Your photo has been removed.',
    ],

    'ui_settings' => [
        'saved' => 'Your preference has been saved.',
        'invalid_value' => 'That value is not allowed for this setting.',
    ],

    'sessions' => [
        'revoked' => 'That device has been signed out.',
        'cannot_revoke_current' => 'You cannot sign out the device you are using now — use log out instead.',
    ],

    'account' => [
        'deleted' => 'Your account has been deleted.',
        'owns_company' => 'You still own one or more companies. Transfer or delete them before deleting your account.',
        'confirm_email' => 'Please type your exact email address to confirm.',
        'confirm_required' => 'Please confirm you understand this cannot be undone.',
    ],

];
