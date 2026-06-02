<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Invite a single employee from the employees screen (outside the wizard).
 *
 * Authorization (is the inviter an owner of the active company) is enforced in
 * the controller against the resolved active company, not here, so this request
 * only shapes the input.
 */
class InviteEmployeeRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
