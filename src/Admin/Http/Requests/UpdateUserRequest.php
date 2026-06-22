<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Update a user from the super-admin console (phase 2.3).
 *
 * Inherits {@see StoreUserRequest} (the phase 1.3 idiom) and relaxes two rules for
 * edits: the password is optional (omit to keep it), and email uniqueness
 * ignores the row being edited. Privilege/state columns remain off-limits to
 * input, exactly as on create.
 */
class UpdateUserRequest extends StoreUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $userId = $this->route('user');

        $rules['email'] = ['required', 'string', 'email', 'max:255', Rule::unique($this->usersTable(), 'email')->ignore($userId)];
        $rules['password'] = ['nullable', 'string', 'min:8'];

        return $rules;
    }
}
