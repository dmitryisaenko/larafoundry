<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create a company member DIRECTLY from the employees screen — the account is
 * provisioned by the owner (name, email, password, roles) instead of sending an
 * email invitation. The counterpart to {@see InviteEmployeeRequest}.
 *
 * Authorization (owner of the active company) is enforced in the controller
 * against the resolved active company, not here, so this request only shapes and
 * validates the input.
 *
 * SECURITY:
 *  - The email must be unique AND is never allowed to be the reserved super-admin
 *    identity (same guard public registration uses).
 *  - `role_ids` are optional and each MUST belong to the owner's active company —
 *    the same company-scoped, fail-closed rule as invite/wizard, so a
 *    foreign/global/template role cannot be granted (IDOR). The action re-checks.
 */
class CreateEmployeeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique($this->userTable(), 'email'),
                // The operator identity must not be claimable here either. Matches
                // CreateNewUser: case-insensitive, so a `not_in` won't do.
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
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => [
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
     * The owner's active company id, or null when there is none.
     */
    protected function activeCompanyId(): ?int
    {
        return $this->user()?->getActiveCompany()?->getKey();
    }

    /**
     * The users table backing the configured auth model (never hard-coded).
     */
    protected function userTable(): string
    {
        $model = config('auth.providers.users.model', User::class);

        return (new $model)->getTable();
    }
}
