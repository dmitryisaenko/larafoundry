<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wizard step 3 — invite employees by email.
 *
 * A list of emails, each validated. `role_id` is intentionally absent — assigning
 * a role on invite is RBAC (phase 1.3). The list may be empty (the owner can skip
 * inviting anyone and finish setup alone).
 */
class StoreCompanyStep3Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invitations' => ['nullable', 'array'],
            'invitations.*' => ['required', 'email', 'max:255', 'distinct'],
        ];
    }

    /**
     * The submitted emails, normalised to a flat list.
     *
     * @return array<int, string>
     */
    public function emails(): array
    {
        return array_values((array) $this->input('invitations', []));
    }
}
