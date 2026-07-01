<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Actions;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;

/**
 * Provisions a brand-new user AND adds them to a company as a member in one step —
 * the "create employee directly" counterpart to the email-invite flow.
 *
 * The account is created verified (`email_verified_at = now`): the owner vouches
 * for the person, so they can sign in and use business features immediately, the
 * same trade-off WordPress's "add user, skip confirmation" makes. Mailbox-proof
 * (the reason invites exist) is deliberately waived here because an owner is
 * asserting the identity, not an anonymous token holder.
 *
 * Password is passed through untouched — the User model casts `password => hashed`,
 * so hashing happens on set (double-hashing would lock the account out).
 *
 * SECURITY (defense in depth): even though CreateEmployeeRequest already
 * company-scopes every role_id, this action RE-LOADS the roles constrained to
 * `$company`, so a non-HTTP caller can never grant a foreign/global/template role.
 */
class CreateEmployeeAction
{
    /**
     * @param  array{name: string, lastname?: string|null, email: string, password: string, role_ids?: array<int, int|string>|null}  $data
     */
    public function execute(Company $company, array $data, ?int $createdById = null): Authenticatable
    {
        $model = config('auth.providers.users.model', User::class);

        $user = $model::create([
            'name' => trim((string) $data['name']),
            'lastname' => ($lastname = trim((string) ($data['lastname'] ?? ''))) !== '' ? $lastname : null,
            'email' => mb_strtolower(trim((string) $data['email'])),
            'password' => $data['password'],
            'locale' => config('larafoundry.locale.default', 'en'),
        ]);

        // email_verified_at is a state column (not fillable) — set it explicitly.
        $user->forceFill(['email_verified_at' => now()])->save();

        $company->addEmployee($user, $createdById, isOwner: false);

        // Grant the chosen roles (company-scoped, idempotent). Guarded so a host
        // User without the RBAC trait still gets created + added, just without roles.
        if (method_exists($user, 'assignRole')) {
            foreach ($this->resolveRoles($company, $data['role_ids'] ?? []) as $role) {
                $user->assignRole($role, $company, assignedById: $createdById);
            }
        }

        return $user;
    }

    /**
     * Load only the roles that genuinely belong to $company (foreign/global/
     * template ids are silently dropped rather than failing the whole create).
     *
     * @param  array<int, int|string>|null  $roleIds
     * @return Collection<int, Role>
     */
    protected function resolveRoles(Company $company, ?array $roleIds)
    {
        $ids = collect($roleIds ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return Role::query()
            ->whereKey($ids)
            ->where('company_id', $company->getKey())
            ->get();
    }
}
