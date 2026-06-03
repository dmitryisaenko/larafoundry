<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a user from the super-admin console (phase 2.3).
 *
 * Shapes input only. The route already sits behind `larafoundry.admin`, so this
 * just authorizes an authenticated request. Privilege/state columns
 * (`user_blocked_at`, `block_code`, `user_deleted_at`) are NEVER accepted from
 * input — they are transitions driven by the block/delete endpoints. `is_admin`
 * IS allowed: granting admin is a legitimate, explicit super-admin action, and
 * the field is validated as a strict boolean rather than mass-assigned blindly.
 */
class StoreUserRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique($this->usersTable(), 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_admin' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The users table to scope uniqueness against (host may rename it; default
     * matches Laravel's convention).
     */
    protected function usersTable(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return (new $model)->getTable();
    }
}
