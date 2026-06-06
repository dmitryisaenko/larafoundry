<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Actions;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Validates and creates a new user during Fortify registration.
 *
 * Bound to Fortify's CreatesNewUsers contract in the service provider, so it
 * replaces the action `fortify:install` would scaffold. Password strength comes
 * from `larafoundry.auth.password_min_length` plus Laravel's `Password::defaults()`
 * — a deliberate upgrade from the donor's `min:3`. Locale defaults to the
 * configured fallback so {@see SetLocale}
 * has a value to work with immediately.
 */
class CreateNewUser implements CreatesNewUsers
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): mixed
    {
        $model = config('auth.providers.users.model', User::class);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique($this->table($model)),
                // The super-admin email is reserved: the operator identity must
                // not be claimable through public registration (phase 1.4). The
                // check is case-insensitive (a `not_in` would be case-sensitive).
                function (string $attribute, mixed $value, callable $fail): void {
                    if (VisitorStatus::isSuperAdminEmail(is_string($value) ? $value : null)) {
                        $fail(__('larafoundry::auth.super_admin.email_reserved'));
                    }
                },
            ],
            'password' => [
                'required', 'string', 'confirmed',
                Password::min((int) config('larafoundry.auth.password_min_length', 8))->defaults(),
            ],
        ])->validate();

        return $model::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'locale' => config('larafoundry.locale.default', 'en'),
        ]);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function table(string $model): string
    {
        return (new $model)->getTable();
    }
}
