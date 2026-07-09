<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Authorization\Events\RoleCreated;
use Dmitryisaenko\LaraFoundry\Authorization\Events\RoleDeleted;
use Dmitryisaenko\LaraFoundry\Authorization\Events\RoleUpdated;
use Dmitryisaenko\LaraFoundry\Authorization\Http\Requests\StoreRoleRequest;
use Dmitryisaenko\LaraFoundry\Authorization\Http\Requests\UpdateRoleRequest;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Authorization\Support\PermissionCatalog;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Concerns\ResolvesActiveCompany;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for the active company's roles (phase 1.3).
 *
 * Every role is resolved THROUGH the active company (anti-IDOR, the same
 * structural-isolation idiom as phase 1.2 invitations): a role belonging to another
 * company simply isn't found (404), so cross-tenant access can't slip past a
 * forgotten guard. On top of that the `roles.*` gates enforce the permission and
 * the structural rules (editable / deletable). Server sets the role flags and
 * company_id — never the request (anti-forgery of global/cross-company roles).
 */
class RoleController extends Controller
{
    use AuthorizesRequests;
    use ResolvesActiveCompany;

    public function __construct(protected PermissionCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $this->authorize('roles.viewAny');

        $company = $this->activeCompany($request);

        return Inertia::render('Authorization/Roles/Index', [
            'roles' => Role::where('company_id', $company->getKey())
                ->withCount('users')
                ->with('permissions:id,slug')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => $this->present($role)),
            'can' => [
                'create' => $request->user()->can('roles.create'),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('roles.create');

        return Inertia::render('Authorization/Roles/Form', [
            'role' => null,
            'modules' => $this->catalog->modules(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('roles.create');

        $company = $this->activeCompany($request);

        $role = Role::create([
            'name' => $request->validated('name'),
            'slug' => $this->uniqueSlug($company, $request->validated('name')),
            'description' => $request->validated('description'),
            'is_global' => false,
            'is_template' => false,
            'is_custom' => true,
            'company_id' => $company->getKey(),
            'created_by_id' => $request->user()->getAuthIdentifier(),
        ]);

        $role->syncPermissions($request->validated('permissions', []));

        RoleCreated::dispatch($role);

        return redirect()
            ->route('authorization.roles.index')
            ->with('status', __('larafoundry::authorization.role_created'));
    }

    public function edit(Request $request, int $role): Response
    {
        $roleModel = $this->companyRole($this->activeCompany($request), $role);

        $this->authorize('roles.update', $roleModel);

        return Inertia::render('Authorization/Roles/Form', [
            'role' => $this->present($roleModel),
            'modules' => $this->catalog->modules(),
        ]);
    }

    public function update(UpdateRoleRequest $request, int $role): RedirectResponse
    {
        $roleModel = $this->companyRole($this->activeCompany($request), $role);

        $this->authorize('roles.update', $roleModel);

        $roleModel->update([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        $roleModel->syncPermissions($request->validated('permissions', []));

        RoleUpdated::dispatch($roleModel);

        return redirect()
            ->route('authorization.roles.index')
            ->with('status', __('larafoundry::authorization.role_updated'));
    }

    public function destroy(Request $request, int $role): RedirectResponse
    {
        $roleModel = $this->companyRole($this->activeCompany($request), $role);

        $this->authorize('roles.delete', $roleModel);

        $roleModel->delete();

        RoleDeleted::dispatch($roleModel);

        return back()->with('status', __('larafoundry::authorization.role_deleted'));
    }

    /**
     * Resolve a role within the active company (anti-IDOR: 404 if it belongs to
     * another company or does not exist).
     */
    protected function companyRole(Company $company, int $roleId): Role
    {
        /** @var Role */
        return Role::where('company_id', $company->getKey())->findOrFail($roleId);
    }

    /**
     * A slug unique within the company (the (company_id, slug) index).
     */
    protected function uniqueSlug(Company $company, string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $suffix = 2;

        while (Role::where('company_id', $company->getKey())->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_custom' => $role->is_custom,
            'editable' => $role->isEditable(),
            'deletable' => $this->roleDeletable($role),
            'users_count' => $role->users_count ?? null,
            'permissions' => $role->permissions->pluck('slug'),
        ];
    }

    /**
     * Whether a role can be deleted, reusing the eager-loaded `users_count` from
     * the index query instead of re-running canBeDeleted()'s COUNT per row (N+1).
     * Falls back to the model check when the count was not loaded (e.g. edit()).
     */
    protected function roleDeletable(Role $role): bool
    {
        if (! $role->is_custom) {
            return false;
        }

        if ($role->users_count !== null) {
            return (int) $role->users_count === 0;
        }

        return $role->canBeDeleted();
    }
}
