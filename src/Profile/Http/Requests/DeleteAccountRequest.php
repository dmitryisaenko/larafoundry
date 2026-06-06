<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirms an account deletion with the current password (phase 5.1).
 *
 * Re-auth on a destructive, irreversible-feeling action: a hijacked session
 * cannot delete the account without also knowing the password. (OAuth-only users
 * have no local password and so cannot self-delete here — a phase 5.3
 * refinement.)
 */
class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => __('larafoundry::auth.password.current_invalid'),
        ];
    }
}
