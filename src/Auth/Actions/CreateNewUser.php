<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Actions;

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
