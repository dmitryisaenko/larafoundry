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
 * HOST SEAM (phase 7): a host that needs extra user-list columns without forking
 * the Vue table subclasses this resource, overrides {@see extra()} and points
 * `config('larafoundry.admin.user_resource')` at the subclass. The base emits an
 * empty `extra_columns`, and `UsersTable.vue` renders whatever cells it finds —
 * so a host column is pure config + one method, no fork. Never expose secrets or
 * heavy relations through `extra()`; keep it to display cells.
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
            'locale' => $this->locale,
            // Auth type is derived from the social provider column (phase 1.1):
            // a user with a `provider_name` signed in via OAuth, otherwise via
            // password. `auth_provider` carries the provider slug for the badge.
            'auth_type' => $this->provider_name !== null ? 'oauth' : 'password',
            'auth_provider' => $this->provider_name,
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
            // Host seam: extra display cells appended after the core columns.
            'extra_columns' => array_values($this->extra($request)),
        ];
    }

    /**
     * Host seam: additional display cells for the user-list table.
     *
     * The core ships none. A host subclasses this resource and overrides this
     * method to append columns (e.g. "used demo?") without touching the Vue
     * table. Each cell is a plain display descriptor:
     *
     *   ['key' => 'demo', 'label' => 'Demo', 'value' => 'Yes', 'badge' => 'emerald']
     *
     * - key   — stable column id (used as the header key; also the i18n key for
     *           the label on the front, so ship a plain string).
     * - label — column header text (an i18n key, translated in Vue).
     * - value — the cell text (already resolved; the table does not interpret it).
     * - badge — (optional) a colour token ('emerald'|'amber'|'rose'|'slate'); when
     *           present the value renders as a pill instead of plain text.
     *
     * @return array<int, array{key: string, label: string, value: string|int|null, badge?: string|null}>
     */
    protected function extra(Request $request): array
    {
        return [];
    }
}
