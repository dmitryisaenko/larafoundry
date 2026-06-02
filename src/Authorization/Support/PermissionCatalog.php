<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Support;

/**
 * Read-only accessor over the permissions catalog config (phase 1.3).
 *
 * The single read-point so the sync command, the gate registrar and the UI all
 * see the same catalog — `config('larafoundry-permissions')`, which is the core
 * default merged with the host's published file. Nothing here touches the
 * database; it only flattens the config shape into the lists callers need.
 */
class PermissionCatalog
{
    /**
     * The grouped permissions config: module => ['label' => ..., 'permissions' => [slug => desc]].
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public function modules(): array
    {
        return config('larafoundry-permissions.permissions', []);
    }

    /**
     * Every permission slug declared in the catalog, flattened.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        $slugs = [];

        foreach ($this->modules() as $module) {
            foreach (array_keys($module['permissions'] ?? []) as $slug) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Flat map of slug => description across all modules.
     *
     * @return array<string, array{module: string, description: string}>
     */
    public function permissions(): array
    {
        $permissions = [];

        foreach ($this->modules() as $moduleKey => $module) {
            foreach (($module['permissions'] ?? []) as $slug => $description) {
                $permissions[$slug] = [
                    'module' => $moduleKey,
                    'description' => $description,
                ];
            }
        }

        return $permissions;
    }

    /**
     * Role templates cloned into each new company.
     *
     * @return array<string, array{name: string, description?: string, permissions: list<string>}>
     */
    public function roleTemplates(): array
    {
        return config('larafoundry-permissions.role_templates', []);
    }

    /**
     * Global roles (applied everywhere, never cloned).
     *
     * @return array<string, array{name: string, description?: string, permissions: list<string>}>
     */
    public function globalRoles(): array
    {
        return config('larafoundry-permissions.global_roles', []);
    }
}
