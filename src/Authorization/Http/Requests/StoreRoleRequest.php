<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Http\Requests;

use Dmitryisaenko\LaraFoundry\Authorization\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a custom company role.
 *
 * Shapes input only. Authorization (the `roles.create` gate) and the server-set
 * flags (is_custom/is_global/is_template/company_id/created_by_id) live in the
 * controller — they must NEVER come from the request, or a member could forge a
 * global or cross-company role.
 *
 * Permissions are constrained to the known catalog so an attacker cannot attach an
 * arbitrary slug that some future gate might honour.
 */
class StoreRoleRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(app(PermissionCatalog::class)->slugs())],
        ];
    }
}
