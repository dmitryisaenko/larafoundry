<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the super-admin affiliates-list query (Ф4.4). Authorization is the canonical
 * super-admin check, the second lock after the `larafoundry.admin` route gate; the
 * whitelisted sort keys keep the controller's orderBy() free of attacker input.
 */
class AdminAffiliatesFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(VisitorStatus::class)->isAdmin($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:active,disabled,pending'],
            'code' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'string', 'in:created_at,code,id'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
