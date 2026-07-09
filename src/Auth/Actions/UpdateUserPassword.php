<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Actions;

use Dmitryisaenko\LaraFoundry\Auth\Events\PasswordUpdated;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Validates and applies an authenticated user's password change.
 *
 * Bound to Fortify's UpdatesUserPasswords contract. Requires the current
 * password (`current_password` rule) to defend against a hijacked session
 * silently swapping the credential.
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(mixed $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => [
                'required', 'string', 'confirmed',
                Password::min((int) config('larafoundry.auth.password_min_length', 8))->defaults(),
            ],
        ], [
            'current_password.current_password' => __('larafoundry::auth.password.current_invalid'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        PasswordUpdated::dispatch($user);
    }
}
