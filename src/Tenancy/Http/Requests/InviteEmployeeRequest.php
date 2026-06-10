<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invite a single employee from the employees screen (outside the wizard).
 *
 * Authorization (is the inviter an owner of the active company) is enforced in
 * the controller against the resolved active company, not here, so this request
 * only shapes the input.
 *
 * `role_id` is optional (role-at-invite). SECURITY: when present it MUST belong to
 * the inviter's active company — same company-scoped, fail-closed rule as the
 * wizard, so a foreign/global/template role cannot be bound (IDOR).
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
            'role_id' => [
                'nullable',
                'integer',
                Rule::exists('roles', 'id')->where(function ($query) {
                    $companyId = $this->activeCompanyId();

                    // `?? -1` keeps the rule failing closed with no active company,
                    // so a null can't be rewritten to whereNull and match a
                    // global/template role.
                    $query->whereNotNull('company_id')
                        ->where('company_id', $companyId ?? -1);
                }),
            ],
        ];
    }

    /**
     * The inviter's active company id, or null when there is none.
     */
    protected function activeCompanyId(): ?int
    {
        $company = $this->user()?->getActiveCompany();

        return $company?->getKey();
    }
}
