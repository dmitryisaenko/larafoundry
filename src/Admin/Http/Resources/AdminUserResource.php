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
     * The optional, opt-in user-list columns a host may switch on (phase 3a).
     *
     * The default install ships NONE of these, so the payload stays privacy-clean
     * (GDPR-friendly for a public package): `phone`/`sex`/`age` are personal data
     * and are only serialised when the operator deliberately opts them in via
     * `larafoundry.admin.user_columns`. `social` is reserved here but wired in
     * phase 3b once social-link storage ships. Anything not in this list is
     * ignored, so a crafted config key can never protrude an arbitrary field.
     *
     * @var list<string>
     */
    public const COLUMN_TOKENS = ['phone', 'sex', 'age', 'social'];

    /**
     * Serialise the personal columns (phone/sex/age) regardless of the opt-in
     * config (phase 3a).
     *
     * The `user_columns` gating is a property of the LIST — it keeps the default
     * user table privacy-clean for a public package. The single-user edit view is
     * a different context: the operator is deliberately opening one user to manage
     * them, so those fields must always be present (otherwise the edit form
     * prefills a blank phone and a save silently wipes the stored number). The
     * controller's `edit()` builds the resource via {@see full()} to set this.
     */
    public bool $withPersonalColumns = false;

    /**
     * A resource that always carries the personal columns, for the single-user
     * edit context (see {@see $withPersonalColumns}). Uses `new static` so a host
     * subclass keeps its own type and `extra()`.
     */
    public static function full(Model $resource): static
    {
        $instance = new static($resource);
        $instance->withPersonalColumns = true;

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $columns = $this->withPersonalColumns ? self::COLUMN_TOKENS : self::enabledColumns();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'lastname' => $this->lastname,
            // Phase 2.4: always a resolvable URL (stored file, external OAuth
            // avatar, or a generated initials placeholder) — never the raw
            // column, so the table can render an <img> without a null check.
            'avatar_url' => $this->avatar_url,
            'email' => $this->email,
            // Opt-in personal columns (phase 3a): omitted from the payload
            // unless the host switched the token on, so the default table is
            // privacy-clean at the wire level, not just visually hidden.
            'phone' => $this->when(in_array('phone', $columns, true), fn () => $this->phone),
            'sex' => $this->when(in_array('sex', $columns, true), fn () => $this->sex),
            // Age is derived from birth_date, never the raw DOB — null-safe when
            // the user has no birth date on file.
            'age' => $this->when(in_array('age', $columns, true), fn () => $this->birth_date?->age),
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
            'block_reason' => $this->user_blocked_status,
            // Kept for backward compatibility; the table now reads the split
            // owned/employee counts below for the "Comp. / Empl." column.
            'companies_count' => $this->whenCounted('companies'),
            'owned_companies_count' => $this->whenCounted('ownedCompanies'),
            'employee_companies_count' => $this->whenCounted('employeeCompanies'),
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
     * The opt-in columns the operator switched on, sanitised against the known
     * tokens (phase 3a).
     *
     * Read straight from `larafoundry.admin.user_columns` and intersected with
     * {@see COLUMN_TOKENS}, so a stray or crafted config value never leaks an
     * unrecognised key downstream. The controller shares the SAME result with the
     * Vue table (which headers/filters to render), so payload and UI stay in step.
     *
     * @return list<string>
     */
    public static function enabledColumns(): array
    {
        $configured = config('larafoundry.admin.user_columns', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_intersect(self::COLUMN_TOKENS, $configured));
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
