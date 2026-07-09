<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Actions;

use Dmitryisaenko\LaraFoundry\Auth\Events\ProfileUpdated;
use Dmitryisaenko\LaraFoundry\Auth\Support\SessionInvalidator;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Validates and applies an authenticated user's profile change (phase 5.1).
 *
 * Bound to Fortify's UpdatesUserProfileInformation contract, replacing the
 * action `fortify:install` would scaffold. Plain profile fields update freely;
 * changing the EMAIL is treated as a security-sensitive operation and hardened
 * against the donor's three holes (recon §2):
 *
 *  - current_password is required (finding #3 — the donor changed email with no
 *    re-auth, so a hijacked session could silently steal the account);
 *  - `email_verified_at` is reset and a fresh verification mail is sent, so an
 *    unverified address never inherits the old one's verified standing;
 *  - the other devices are signed out, so a session opened against the old
 *    address cannot keep riding after the address moves.
 *
 * The reserved super-admin email cannot be claimed here either, mirroring
 * {@see CreateNewUser} — the operator identity is not assignable through a
 * profile edit any more than through registration.
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(mixed $user, array $input): void
    {
        $emailChanging = $this->emailIsChanging($user, $input);

        // Default error bag (not a named one): the profile hub reads `form.errors`
        // directly, so the package's pages work without the host wiring named-bag
        // sharing into Inertia — matching the other core screens.
        Validator::make($input, $this->rules($user, $emailChanging), [
            'current_password.current_password' => __('larafoundry::auth.password.current_invalid'),
        ])->validate();

        $emailChanging
            ? $this->changeEmail($user, $input)
            : $user->fill($this->profileFields($input))->save();

        ProfileUpdated::dispatch($user, $emailChanging);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function emailIsChanging(mixed $user, array $input): bool
    {
        if (! array_key_exists('email', $input)) {
            return false;
        }

        return mb_strtolower((string) $input['email']) !== mb_strtolower((string) $user->email);
    }

    /**
     * Validation rules. current_password is demanded ONLY when the email is
     * actually changing, so a plain name/phone edit does not nag for a password.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(mixed $user, bool $emailChanging): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'sex' => ['nullable', 'string', 'max:1'],
            'birth_date' => ['nullable', 'date'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique($this->table($user))->ignore($user->getKey()),
                // The operator identity is reserved: it must not be claimable by
                // editing an ordinary user's email to it. Case-insensitive (a
                // not_in would be case-sensitive), mirroring CreateNewUser.
                function (string $attribute, mixed $value, callable $fail): void {
                    if (VisitorStatus::isSuperAdminEmail(is_string($value) ? $value : null)) {
                        $fail(__('larafoundry::auth.super_admin.email_reserved'));
                    }
                },
            ],
        ];

        if ($emailChanging) {
            $rules['current_password'] = ['required', 'string', 'current_password:web'];
        }

        return $rules;
    }

    /**
     * Plain profile attributes that update without re-auth (email handled apart).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function profileFields(array $input): array
    {
        $fields = ['name', 'lastname', 'middlename', 'phone', 'country', 'sex', 'birth_date'];

        $data = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        return $data;
    }

    /**
     * Apply an email change: write the new address (verification reset), evict
     * the other devices and send a fresh verification mail.
     *
     * @param  array<string, mixed>  $input
     */
    protected function changeEmail(mixed $user, array $input): void
    {
        $user->forceFill(array_merge($this->profileFields($input), [
            'email' => $input['email'],
            'email_verified_at' => null,
        ]))->save();

        SessionInvalidator::invalidateOthers($user, app('session.store')->getId());

        // Registered drives Laravel's SendEmailVerificationNotification listener,
        // so the new address gets a verification mail exactly as at sign-up.
        event(new Registered($user));
    }

    protected function table(mixed $user): string
    {
        /** @var Model $user */
        return $user->getTable();
    }
}
