<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Actions;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyInvitationSent;
use Dmitryisaenko\LaraFoundry\Tenancy\Jobs\SendCompanyInvitationJob;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Support\Collection;

/**
 * Creates pending invitations for a set of invites and queues their delivery.
 *
 * Used by wizard step 3 and the standalone invite action. Each invite is an
 * `['email' => string, 'role_id' => ?int]` pair: `role_id` is the optional
 * company-scoped role to grant on acceptance (role-at-invite), null by default.
 * Each email gets a strong unique token and an expiry from config. Already-pending
 * invitations for the same (company, email) are refreshed rather than duplicated
 * (the unique index forbids a second row), and existing members are skipped.
 *
 * SECURITY (defense in depth): even though the FormRequests company-scope the
 * role, the action RE-CHECKS that each `role_id` belongs to `$company` — a
 * non-HTTP caller can't bind a foreign/global/template role. An invalid id
 * degrades to null (no role) rather than failing the whole invite.
 */
class InviteEmployeesAction
{
    /**
     * @param  array<int, array{email: string, role_id?: int|null}>  $invites
     * @return Collection<int, CompanyInvitation>
     */
    public function execute(Company $company, array $invites, ?int $invitedBy = null): Collection
    {
        $expiryDays = (int) config('larafoundry.tenancy.invitation_expiry_days', 7);

        // NB: wrap mb_strtolower in a closure — passing it as a string callable
        // to Collection::map leaks the item KEY as the $encoding argument.
        $memberEmails = $company->users()->pluck('email')
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();

        return collect($invites)
            ->map(fn (array $invite): array => [
                'email' => trim((string) ($invite['email'] ?? '')),
                'role_id' => $this->resolveRoleId($company, $invite['role_id'] ?? null),
            ])
            ->filter(fn (array $invite): bool => $invite['email'] !== '')
            ->unique(fn (array $invite) => mb_strtolower($invite['email']))
            ->reject(fn (array $invite): bool => in_array(mb_strtolower($invite['email']), $memberEmails, true))
            ->map(function (array $invite) use ($company, $expiryDays, $invitedBy): CompanyInvitation {
                $invitation = $company->invitations()->updateOrCreate(
                    ['email' => $invite['email']],
                    [
                        'role_id' => $invite['role_id'],
                        'token' => CompanyInvitation::generateToken(),
                        'status' => CompanyInvitation::STATUS_PENDING,
                        'invited_by' => $invitedBy,
                        'expires_at' => now()->addDays($expiryDays),
                        'accepted_at' => null,
                    ],
                );

                SendCompanyInvitationJob::dispatch($invitation);
                CompanyInvitationSent::dispatch($invitation);

                return $invitation;
            })
            ->values();
    }

    /**
     * A role_id is honored only if it belongs to THIS company; anything else
     * (foreign, global, template, or unknown) degrades to null.
     *
     * NB: there is deliberately no "you may only grant a role no stronger than your
     * own" ceiling here, because inviting is owner-only (the controllers resolve
     * the company via ownedActiveCompany), and an owner already holds the whole
     * catalog — so granting any company role is strictly less than they have. If
     * invite ability is ever delegated to a non-owner permission, add that ceiling.
     */
    protected function resolveRoleId(Company $company, int|string|null $roleId): ?int
    {
        if ($roleId === null || $roleId === '') {
            return null;
        }

        $roleId = (int) $roleId;

        $belongs = Role::query()
            ->whereKey($roleId)
            ->where('company_id', $company->getKey())
            ->exists();

        return $belongs ? $roleId : null;
    }
}
