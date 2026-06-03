<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises one user for the super-admin console (phase 2.3).
 *
 * Deliberately omits the donor's social links (recon finding #6, PII): the
 * operator list is for moderation, not profiling. Exposes identity, contact,
 * verification, account state and activity — enough to find, vet and act on a
 * user. Secrets (password, tokens, 2FA) are already hidden by the user model's
 * `laraFoundryHidden()`, but this resource never reads them anyway.
 *
 * @property Model $resource
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lastname' => $this->lastname,
            // Phase 2.4: always a resolvable URL (stored file, external OAuth
            // avatar, or a generated initials placeholder) — never the raw
            // column, so the table can render an <img> without a null check.
            'avatar_url' => $this->avatar_url,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'is_admin' => (bool) $this->is_admin,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'is_blocked' => $this->user_blocked_at !== null,
            'is_deleted' => $this->user_deleted_at !== null,
            'block_code' => $this->block_code,
            'companies_count' => $this->whenCounted('companies'),
            'created_at' => $this->created_at?->toISOString(),
            'registered_date' => $this->created_at?->format('d.m.Y'),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'last_activity_human' => $this->last_activity_at
                ?->locale(app()->getLocale())
                ->diffForHumans(),
        ];
    }
}
