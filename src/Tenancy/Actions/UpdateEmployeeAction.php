<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Actions;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Media\Actions\StoreUploadedFileAction;
use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRoleChanged;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Owner updates an existing member of a company: name, lastname, avatar and roles.
 * The "edit employee" counterpart to {@see CreateEmployeeAction}. Never touches
 * email/password — those are the member's own login credentials.
 *
 * Avatar file I/O runs OUTSIDE the DB transaction (like the profile AvatarController):
 * the new file is written first, the identity + roles are committed as one unit, and
 * the previous file is deleted only after the commit — so a mid-update failure can't
 * leave the row pointing at a deleted file.
 *
 * SECURITY (defense in depth): even though the FormRequest company-scopes every
 * role_id, this action RE-LOADS the roles constrained to `$company`, so a foreign/
 * global/template role can never be granted through syncRoles.
 */
class UpdateEmployeeAction
{
    public function __construct(
        private readonly StoreUploadedFileAction $storeFile,
        private readonly MediaStorage $media,
    ) {}

    /**
     * @param  array{name: string, lastname?: string|null, role_ids?: array<int, int|string>|null, avatar?: UploadedFile|null, remove_avatar?: bool}  $data
     */
    public function execute(Company $company, Authenticatable $user, array $data, ?int $actorId = null): void
    {
        $previousAvatar = (string) ($user->getAttribute('avatar') ?? '');

        // Resolve the avatar change up front (file store is I/O, kept out of the tx).
        // $avatarChange: ['path' => string, 'dropOriginal' => ?array] | 'remove' | null
        $avatarChange = null;

        if (($file = $data['avatar'] ?? null) !== null) {
            $stored = $this->storeFile->execute(
                $file,
                'avatar',
                (string) config('larafoundry-media.paths.avatars', 'avatars'),
                ['variants' => ['avatar']],
            );
            $path = $stored->variant('avatar');
            $avatarChange = [
                'path' => $path,
                // store() also writes the full-size original; drop it (only the
                // 256px variant lives on the model), same as the profile flow.
                'dropOriginal' => $stored->path !== $path ? ['path' => $stored->path, 'disk' => $stored->disk] : null,
            ];
        } elseif (! empty($data['remove_avatar'])) {
            $avatarChange = 'remove';
        }

        // Resolve the role change up front so we can both sync it and detect a
        // real change for the audit event. Only when the caller opted in via
        // `manage_roles` and the host User carries the RBAC trait.
        $manageRoles = ! empty($data['manage_roles']) && method_exists($user, 'syncRoles');
        $rolesToSync = $manageRoles ? $this->resolveRoles($company, $data['role_ids'] ?? []) : [];
        $newRoleIds = $this->roleIds($rolesToSync);
        // Change detection needs the OLD set. If the host User can't report it
        // (partial RBAC surface: syncRoles present, getRolesInCompany absent) we
        // cannot tell a real change from a no-op, so we do not emit the event
        // rather than claim a spurious change.
        $canDetectRoleChange = $manageRoles && method_exists($user, 'getRolesInCompany');
        $oldRoleIds = $canDetectRoleChange
            ? $this->roleIds($user->getRolesInCompany($company)->all())
            : [];

        DB::transaction(function () use ($user, $company, $data, $actorId, $avatarChange, $manageRoles, $rolesToSync) {
            $attrs = [
                'name' => trim((string) $data['name']),
                'lastname' => ($lastname = trim((string) ($data['lastname'] ?? ''))) !== '' ? $lastname : null,
            ];

            if (is_array($avatarChange)) {
                $attrs['avatar'] = $avatarChange['path'];
            } elseif ($avatarChange === 'remove') {
                $attrs['avatar'] = null;
            }

            $user->forceFill($attrs)->save();

            // Replace the member's role set for THIS company — but ONLY when the
            // caller explicitly opts in via `manage_roles`. This keeps role sync a
            // full-set REPLACE (an empty set clears roles) while making an update
            // that doesn't mean to touch roles (no flag) leave them untouched, so a
            // partial edit can never silently wipe them. Guarded on syncRoles so a
            // host User without the RBAC trait still gets its identity updated.
            if ($manageRoles) {
                $user->syncRoles($rolesToSync, $company, $actorId);
            }
        });

        // Post-commit file cleanup (idempotent; a missing file no-ops).
        if (is_array($avatarChange)) {
            if ($avatarChange['dropOriginal'] !== null) {
                $this->media->delete($avatarChange['dropOriginal']['path'], $avatarChange['dropOriginal']['disk']);
            }
            $this->deleteStored($previousAvatar);
        } elseif ($avatarChange === 'remove') {
            $this->deleteStored($previousAvatar);
        }

        // Audit the role change only when we could read the old set AND it
        // actually changed — a plain identity edit, or a role submit that matches
        // the current set, logs nothing (matrix row 7).
        if ($canDetectRoleChange && $oldRoleIds !== $newRoleIds) {
            EmployeeRoleChanged::dispatch($company, $user, $newRoleIds);
        }
    }

    /**
     * Sorted, de-duplicated integer ids for a set of roles — the comparable shape
     * used both to detect a change and to record it.
     *
     * @param  array<int, Role>  $roles
     * @return array<int, int>
     */
    protected function roleIds(array $roles): array
    {
        return collect($roles)
            ->map(fn ($role) => (int) $role->getKey())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Roles that genuinely belong to $company (foreign/global/template ids dropped).
     *
     * @param  array<int, int|string>|null  $roleIds
     * @return array<int, Role>
     */
    protected function resolveRoles(Company $company, ?array $roleIds): array
    {
        $ids = collect($roleIds ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return Role::query()
            ->whereKey($ids)
            ->where('company_id', $company->getKey())
            ->get()
            ->all();
    }

    /**
     * Delete a previously stored avatar file, skipping external URLs (an OAuth
     * provider may have set an absolute URL we don't own). Mirrors AvatarController.
     */
    protected function deleteStored(string $value): void
    {
        $value = trim($value);

        if ($value === ''
            || str_starts_with($value, '//')
            || str_starts_with($value, 'data:')
            || str_contains($value, '://')) {
            return;
        }

        $this->media->delete($value);
    }
}
