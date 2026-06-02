<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Actions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Validates and applies a password reset.
 *
 * Bound to Fortify's ResetsUserPasswords contract. Same strength policy as
 * registration so the rules can never drift apart.
 */
class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function reset(mixed $user, array $input): void
    {
        Validator::make($input, [
            'password' => [
                'required', 'string', 'confirmed',
                Password::min((int) config('larafoundry.auth.password_min_length', 8))->defaults(),
            ],
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}
