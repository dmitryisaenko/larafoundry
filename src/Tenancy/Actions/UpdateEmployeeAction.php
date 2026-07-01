<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Actions;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Media\Actions\StoreUploadedFileAction;
use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
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

        DB::transaction(function () use ($company, $user, $data, $actorId, $avatarChange) {
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
            if (! empty($data['manage_roles']) && method_exists($user, 'syncRoles')) {
                $user->syncRoles($this->resolveRoles($company, $data['role_ids'] ?? []), $company, $actorId);
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
