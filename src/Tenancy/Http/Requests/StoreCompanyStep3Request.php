<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Wizard step 3 — invite employees, optionally with a role (role-at-invite).
 *
 * Each invite is `{ email, role_id }`. `role_id` is optional (the default is
 * "Specify later" = no role, the original behaviour). The list may be empty (the
 * owner can skip inviting anyone and finish setup alone).
 *
 * SECURITY: a submitted `role_id` MUST belong to the inviter's active company. We
 * scope the existence rule to that company so an attacker cannot bind a foreign,
 * global or template role (IDOR). The check fails closed when there is no active
 * company — a null company id must NOT fall through to `whereNull` and match a
 * global/template role (whose `company_id` is NULL).
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
            'invitations.*.email' => ['required', 'email', 'max:255', 'distinct:ignore_case'],
            'invitations.*.role_id' => [
                'nullable',
                'integer',
                Rule::exists('roles', 'id')->where(function ($query) {
                    $companyId = $this->activeCompanyId();

                    // Company-scope the role. `?? -1` keeps the rule failing closed
                    // when there is no active company: a null would otherwise be
                    // rewritten to `whereNull('company_id')` and match a
                    // global/template role. `whereNotNull` is belt-and-suspenders.
                    $query->whereNotNull('company_id')
                        ->where('company_id', $companyId ?? -1);
                }),
            ],
        ];
    }

    /**
     * The submitted invites, normalised to `[{email, role_id|null}, …]`.
     *
     * Defensive against a flat string item too (older clients), so the action
     * always receives email+role_id pairs.
     *
     * @return array<int, array{email: string, role_id: int|null}>
     */
    public function invitations(): array
    {
        return collect((array) $this->input('invitations', []))
            ->map(function ($invite): array {
                $email = is_array($invite) ? (string) ($invite['email'] ?? '') : (string) $invite;
                $roleId = is_array($invite) ? ($invite['role_id'] ?? null) : null;

                return [
                    'email' => trim($email),
                    'role_id' => ($roleId === null || $roleId === '') ? null : (int) $roleId,
                ];
            })
            ->filter(fn (array $invite): bool => $invite['email'] !== '')
            ->values()
            ->all();
    }

    /**
     * The inviter's active company id, or null when there is none (the role rule
     * then matches nothing — fail closed).
     */
    protected function activeCompanyId(): ?int
    {
        $company = $this->user()?->getActiveCompany();

        return $company?->getKey();
    }
}
