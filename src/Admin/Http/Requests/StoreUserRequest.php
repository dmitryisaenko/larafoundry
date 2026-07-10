<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Requests;

use Dmitryisaenko\LaraFoundry\Profile\Models\UserSocialLink;
use Dmitryisaenko\LaraFoundry\Rules\HttpUrl;
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
            'middlename' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique($this->usersTable(), 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            // sex is stored as a single character (canon 'm'/'f'), matching the
            // self-profile action — one value for sex across the whole core.
            'sex' => ['nullable', 'string', 'max:1'],
            'birth_date' => ['nullable', 'date'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_admin' => ['sometimes', 'boolean'],
            // Social links are optional and gated on the front by the `social`
            // column token; the rules are always present (accepting them is
            // harmless) so a submit is validated regardless of front-end gating.
            'social_links' => ['sometimes', 'array'],
            'social_links.*.platform' => ['required_with:social_links', 'string', Rule::in(UserSocialLink::platforms())],
            // `url` shapes the string; HttpUrl locks the scheme to http(s) so a
            // stored link can never render a `javascript:`/`data:` href (XSS).
            'social_links.*.url' => ['required_with:social_links.*.platform', 'string', 'max:500', 'url', new HttpUrl],
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
