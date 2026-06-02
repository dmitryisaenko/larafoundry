<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry — permissions catalog (phase 1.3 RBAC)
|--------------------------------------------------------------------------
| The single source of truth for which permissions, global roles and role
| templates exist. `php artisan larafoundry:permissions:sync` reads THIS file
| and idempotently upserts the matching rows (updateOrCreate by slug). Editing a
| description and re-running sync updates in place; adding a slug creates it.
|
| The CORE ships only permissions for its own modules (profile, companies,
| invitations, company management). Domain permissions (orders, production,
| warehouse, …) are HOST territory: publish this file
| (`vendor:publish --tag=larafoundry-permissions`) and add your modules/templates
| here, then re-run sync.
|
| Super-admin is NOT a role here — it is an identity flag resolved by the core's
| VisitorStatus (decision D1.3-e) and short-circuits every check via Gate::before.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Permissions by module
    |--------------------------------------------------------------------------
    | 'module_key' => ['label' => 'UI label', 'permissions' => ['slug' => 'description']]
    | Slugs use dot notation `module.action` and are globally unique.
    */
    'permissions' => [

        'profile' => [
            'label' => 'Profile',
            'permissions' => [
                'profile.view' => 'View own profile',
                'profile.edit' => 'Edit own profile',
            ],
        ],

        'company_base' => [
            'label' => 'Company — base actions',
            'permissions' => [
                'companies.create' => 'Create a company',
                'companies.view' => 'View the companies list',
                'companies.switch' => 'Switch between companies',
                'invitations.view' => 'View invitations',
                'invitations.accept' => 'Accept invitations',
                'invitations.decline' => 'Decline invitations',
            ],
        ],

        'company_management' => [
            'label' => 'Company — management',
            'permissions' => [
                'company.settings.view' => 'View company settings',
                'company.settings.update' => 'Update company settings',
                'company.employees.view' => 'View employees',
                'company.employees.invite' => 'Invite employees',
                'company.employees.assign_role' => 'Assign roles to employees',
                'company.employees.grant_permissions' => 'Grant or revoke individual employee permissions',
                'company.employees.remove' => 'Remove employees',
                'company.roles.view' => 'View company roles',
                'company.roles.create' => 'Create company roles',
                'company.roles.update' => 'Update company roles',
                'company.roles.delete' => 'Delete company roles',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Role templates
    |--------------------------------------------------------------------------
    | Cloned into every new company on creation (CloneCompanyRolesJob). The core
    | ships ONE neutral starter (decision D1.3-a) — the host adds its real domain
    | templates (manager, accountant, …) after publishing this file.
    |
    | 'slug' => ['name' => '...', 'description' => '...', 'permissions' => ['slug', ...]]
    | A '*' in permissions means "all permissions" (resolved at sync time).
    */
    'role_templates' => [

        'member' => [
            'name' => 'Member',
            'description' => 'Base company member — extend this set for your app',
            'permissions' => [
                'company.settings.view',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global roles
    |--------------------------------------------------------------------------
    | is_global=true, company_id=NULL — apply everywhere, never cloned. The core
    | ships only `authenticated`, auto-assigned to every registered user (see
    | AssignAuthenticatedRole). super_admin is intentionally absent (it is a
    | VisitorStatus identity flag, not a role).
    */
    'global_roles' => [

        'authenticated' => [
            'name' => 'Authenticated User',
            'description' => 'Base permissions for every registered user',
            'permissions' => [
                'profile.view',
                'profile.edit',
                'companies.create',
                'companies.view',
                'companies.switch',
                'invitations.view',
                'invitations.accept',
                'invitations.decline',
            ],
        ],

    ],

];
