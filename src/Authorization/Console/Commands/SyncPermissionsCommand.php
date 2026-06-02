<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Console\Commands;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Permission;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Authorization\Support\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Sync the permissions catalog (config) into the database (phase 1.3).
 *
 * Idempotent: re-running upserts by slug, so editing a description or adding a
 * permission and re-syncing is safe and non-destructive. `--fresh` wipes
 * permissions/roles first (dev convenience); `--cleanup` removes rows no longer in
 * the catalog after confirmation. Reads through {@see PermissionCatalog} so the
 * core default merged with the host's published file is the single source.
 */
class SyncPermissionsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'larafoundry:permissions:sync
                            {--fresh : Delete all existing permissions and roles before syncing}
                            {--cleanup : Remove permissions/roles no longer present in the catalog}
                            {--force : Force the operation to run when in production}';

    protected $description = 'Sync permissions, global roles and role templates from config into the database';

    public function handle(PermissionCatalog $catalog): int
    {
        $this->info('LaraFoundry: syncing permissions and roles…');

        if ($this->option('fresh')) {
            // --fresh cascades: deleting roles/permissions wipes every user_roles
            // and role_permissions row (FK cascade) — i.e. ALL assignment data.
            // Guard it behind production confirmation, like migrate:fresh.
            if (! $this->confirmToProceed('This deletes ALL permissions, roles and assignments')) {
                return self::FAILURE;
            }

            Permission::query()->delete();
            Role::query()->delete();
            $this->warn('Deleted all existing permissions and roles (--fresh).');
        }

        $permissions = $this->syncPermissions($catalog);
        $globalRoles = $this->syncGlobalRoles($catalog);
        $templates = $this->syncRoleTemplates($catalog);

        if ($this->option('cleanup')) {
            $this->cleanupOrphaned($catalog);
        }

        $this->table(
            ['Type', 'Created', 'Updated'],
            [
                ['Permissions', $permissions['created'], $permissions['updated']],
                ['Global roles', $globalRoles['created'], $globalRoles['updated']],
                ['Role templates', $templates['created'], $templates['updated']],
            ],
        );

        $this->info('Sync complete.');

        return self::SUCCESS;
    }

    /**
     * @return array{created: int, updated: int}
     */
    protected function syncPermissions(PermissionCatalog $catalog): array
    {
        $created = 0;
        $updated = 0;

        foreach ($catalog->permissions() as $slug => $meta) {
            $permission = Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $meta['description'],
                    'module' => $meta['module'],
                    'description' => $meta['description'],
                ],
            );

            $permission->wasRecentlyCreated ? $created++ : $updated++;
        }

        return compact('created', 'updated');
    }

    /**
     * @return array{created: int, updated: int}
     */
    protected function syncGlobalRoles(PermissionCatalog $catalog): array
    {
        $created = 0;
        $updated = 0;

        foreach ($catalog->globalRoles() as $slug => $data) {
            $role = Role::updateOrCreate(
                ['slug' => $slug, 'is_global' => true, 'company_id' => null],
                [
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'is_global' => true,
                    'is_template' => false,
                ],
            );

            $this->syncRolePermissions($role, $data['permissions']);

            $role->wasRecentlyCreated ? $created++ : $updated++;
        }

        return compact('created', 'updated');
    }

    /**
     * @return array{created: int, updated: int}
     */
    protected function syncRoleTemplates(PermissionCatalog $catalog): array
    {
        $created = 0;
        $updated = 0;

        foreach ($catalog->roleTemplates() as $slug => $data) {
            $role = Role::updateOrCreate(
                ['slug' => $slug, 'is_template' => true, 'company_id' => null],
                [
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'is_global' => false,
                    'is_template' => true,
                ],
            );

            $this->syncRolePermissions($role, $data['permissions']);

            $role->wasRecentlyCreated ? $created++ : $updated++;
        }

        return compact('created', 'updated');
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    protected function syncRolePermissions(Role $role, array $permissionSlugs): void
    {
        $ids = in_array('*', $permissionSlugs, true)
            ? Permission::query()->pluck('id')
            : Permission::whereIn('slug', $permissionSlugs)->pluck('id');

        $role->permissions()->sync($ids);
    }

    protected function cleanupOrphaned(PermissionCatalog $catalog): void
    {
        $permissionSlugs = $catalog->slugs();
        $globalSlugs = array_keys($catalog->globalRoles());
        $templateSlugs = array_keys($catalog->roleTemplates());

        $orphanPermissions = Permission::whereNotIn('slug', $permissionSlugs)->get();
        $orphanGlobal = Role::where('is_global', true)->whereNotIn('slug', $globalSlugs)->get();
        $orphanTemplates = Role::where('is_template', true)
            ->where('is_global', false)
            ->whereNotIn('slug', $templateSlugs)
            ->get();

        $total = $orphanPermissions->count() + $orphanGlobal->count() + $orphanTemplates->count();

        if ($total === 0) {
            $this->info('No orphaned items to clean up.');

            return;
        }

        if (! $this->confirm("Delete {$total} orphaned permission(s)/role(s) not in the catalog?", false)) {
            $this->info('Cleanup cancelled.');

            return;
        }

        $orphanPermissions->each->delete();
        $orphanGlobal->each->delete();
        $orphanTemplates->each->delete();

        $this->warn("Removed {$total} orphaned item(s).");
    }
}
