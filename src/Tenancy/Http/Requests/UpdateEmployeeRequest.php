<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Owner edits an existing member of the active company: display identity (name +
 * lastname), avatar and roles. Deliberately does NOT touch email or password —
 * those are login credentials the member owns; an owner adjusts who someone is in
 * the company (name shown, avatar, roles), not how they authenticate.
 *
 * Authorization (owner of the active company + the target being a member of it) is
 * enforced in the controller against the resolved active company, not here.
 *
 * SECURITY: `role_ids` must each belong to the owner's active company — the same
 * company-scoped, fail-closed rule as invite/create (the action re-checks too).
 */
class UpdateEmployeeRequest extends FormRequest
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
        $mimes = config('larafoundry-media.avatar_mimes', ['jpg', 'jpeg', 'png', 'webp']);
        $maxKb = (int) config('larafoundry-media.max_upload_kb', 5120);

        return [
            'name' => ['required', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            // `role_ids` is the COMPLETE desired role set, applied (synced) only when
            // the caller opts in with `manage_roles` — so a partial update that omits
            // it never silently wipes a member's roles. An empty set with the flag on
            // clears them (the "uncheck everything" case, which multipart sends as an
            // absent array).
            'manage_roles' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'nullable', 'array'],
            'role_ids.*' => [
                'integer',
                Rule::exists('roles', 'id')->where(function ($query) {
                    $companyId = $this->user()?->getActiveCompany()?->getKey();

                    $query->whereNotNull('company_id')
                        ->where('company_id', $companyId ?? -1);
                }),
            ],
            'avatar' => [
                'nullable',
                'image',
                'mimes:'.implode(',', is_array($mimes) ? $mimes : ['jpg', 'jpeg', 'png', 'webp']),
                'max:'.$maxKb,
            ],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }
}
