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
            // Part of the display name, not gated PII — always present so the edit
            // form can prefill it (a blank prefill + save would wipe it).
            'middlename' => $this->middlename,
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
            // The RAW date of birth is exposed ONLY in the single-user edit context
            // (full()), never in the list — the list derives `age` so the default
            // payload never carries a plain DOB. The edit form needs the real value
            // to prefill the date input (a blank prefill + save would wipe it).
            'birth_date' => $this->when(
                $this->withPersonalColumns,
                fn () => optional($this->birth_date)->format('Y-m-d'),
            ),
            // Social links (phase 3b): opt-in like the other personal columns. A
            // list of {platform, url} in stored order — the list eager-loads the
            // relation only when the token is on; the edit view carries them via
            // full(). Only platform/url are exposed (never ids), and the url is
            // scheme-locked on write so a rendered href is safe.
            'social_links' => $this->when(
                in_array('social', $columns, true),
                fn () => $this->socialLinks
                    ->map(fn ($link) => ['platform' => $link->platform, 'url' => $link->url])
                    ->values(),
            ),
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
            // Humanized "x ago" for the reason line under the name (legacy parity):
            // null when the state does not apply, so the table renders nothing.
            'blocked_at_human' => $this->user_blocked_at
                ?->locale(app()->getLocale())
                ->diffForHumans(),
            'deleted_at_human' => $this->user_deleted_at
                ?->locale(app()->getLocale())
                ->diffForHumans(),
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
            // Stale = last active before the "recent activity" window (legacy
            // parity: the table flags an old last-activity in a warning colour).
            // Reuses the console's existing `active_within_days` threshold. False
            // when the user has never been active (rendered as "-").
            'last_activity_stale' => $this->last_activity_at !== null
                && $this->last_activity_at->lt(
                    now()->subDays((int) config('larafoundry.admin.active_within_days', 30)),
                ),
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
     * table. Every cell carries `key` + `label` and then takes ONE of two forms.
     *
     * Shared keys:
     * - key   — stable column id (used as the header key; also the i18n key for
     *           the label on the front, so ship a plain string).
     * - label — column header text (an i18n key, translated in Vue).
     *
     * FORM 1 — text (the original): a resolved string, optionally as a pill.
     *
     *   ['key' => 'demo', 'label' => 'Demo', 'value' => 'Yes', 'badge' => 'emerald']
     *
     * - value — the cell text (already resolved; the table does not interpret it).
     * - badge — (optional) a colour token ('emerald'|'amber'|'rose'|'slate'); when
     *           present the value renders as a pill instead of plain text.
     *
     * FORM 2 — a set of small badges, each of which the operator can click to act
     * on that user straight from the list (no need to open the edit card):
     *
     *   [
     *       'key'   => 'access',
     *       'label' => 'Access',
     *       'items' => [
     *           ['key' => 'texts',   'text' => 'T',      'tone' => 'muted', 'title' => 'AI texts: as for everyone (on)'],
     *           ['key' => 'learner', 'text' => 'L',      'tone' => 'on',    'title' => 'Study materials: allowed'],
     *           ['key' => 'search',  'icon' => 'search', 'tone' => 'off',   'title' => 'YouTube search: blocked'],
     *           ['key' => 'audio',   'icon' => 'audio',  'tone' => 'muted', 'title' => 'mp3: as for everyone (off)'],
     *       ],
     *   ]
     *
     * One badge:
     *
     * - key       — (required) stable badge id; it is what travels up in the click
     *               event, so the host knows which switch was hit.
     * - text      — (one of two) a short caption, 1-4 characters ('T', 'L', 'D',
     *               'Tabs', '$'). Mutually exclusive with `icon`.
     * - icon      — (one of two) either a glyph NAME from the package's short
     *               common set ('search', 'audio' — see `cellIcons.js`) or the
     *               host's OWN glyph as data:
     *
     *                   ['path' => 'M4 3h10l6 6v12a1 1 0 01-1 1H4a1 1 0 01-1-1V4z']
     *
     *               with optional `view_box` (default '0 0 24 24', taken only as
     *               four space-separated numbers), `fill` ('currentColor' or
     *               'none'; 'none' by default once a stroke is given) and
     *               `stroke_width` for outline glyphs. Shipping your own glyph is
     *               the ORDINARY route, not a workaround — what a switch looks
     *               like in one application's console is not the package's
     *               business, so the common set stays short deliberately.
     *               Mutually exclusive with `text`: shipping both draws the glyph
     *               and drops the caption — except when the glyph is unusable
     *               (unknown name, no path, a path over 4096 characters, a
     *               malformed shape), where `text` is exactly the fallback.
     * - tone      — (required) 'on' (green) / 'off' (red) / 'muted' (grey) /
     *               'neutral' (the table's own text colour).
     * - title     — (optional) hover hint. A ready-made string; the table does not
     *               translate it.
     * - clickable — (optional, default true). False renders a plain, inert badge.
     *
     * `tone` and `icon` are validated on the Vue side, not here: an unknown value
     * degrades (neutral colour / caption fallback) instead of breaking the row, so
     * this method stays a pure description, as it always was.
     *
     * A cell carries EITHER `value` OR `items`. Shipping both is a malformed cell:
     * the table renders `items` and ignores `value`. An empty `items` renders a
     * dash, just like an empty `value`.
     *
     * The table never stores badge state and never issues the request: it only
     * emits "badge X of column Y was clicked for user Z" and the host page acts.
     *
     * HEADER BADGES — a badge column may also carry an optional `head`, badges of
     * exactly the same shape, showing the SHARED (not per-user) state of those
     * switches above the column:
     *
     *   ['key' => 'access', 'label' => 'Access', 'head' => [...], 'items' => [...]]
     *
     * Without `head` the header renders `label`, as it always has. With it the
     * header renders the badges and `label` moves into the header's tooltip and
     * accessible name, so the column never loses its title for a screen reader.
     * A header badge NEVER clicks, even with `clickable` set: it speaks about
     * everyone, and `extra-action` carries a user the header does not have —
     * shared settings are edited on their own screen. `head` is read from the
     * first cell that ACTUALLY carries it, so building it conditionally does not
     * lose the header when the first-sorted user happens to omit it. An empty or
     * malformed `head` falls back to the label.
     *
     * Keep `extra()` to display cells — never expose secrets or heavy relations.
     *
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     value?: string|int|null,
     *     badge?: string|null,
     *     head?: array<int, array{
     *         key: string,
     *         text?: string,
     *         icon?: string|array{path: string, view_box?: string, fill?: string, stroke_width?: float|int|string},
     *         tone: string,
     *         title?: string|null,
     *     }>,
     *     items?: array<int, array{
     *         key: string,
     *         text?: string,
     *         icon?: string|array{path: string, view_box?: string, fill?: string, stroke_width?: float|int|string},
     *         tone: string,
     *         title?: string|null,
     *         clickable?: bool,
     *     }>,
     * }>
     */
    protected function extra(Request $request): array
    {
        return [];
    }
}
