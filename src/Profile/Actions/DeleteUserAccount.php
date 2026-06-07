<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Actions;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Self-service account deletion, with the server-side guard the donor lacked
 * (phase 5.1).
 *
 * The donor only hid the delete form on the frontend when the user belonged to a
 * company (recon finding #1), so a direct request would still soft-delete an
 * owner and orphan the company (finding #4). Here the guard is on the SERVER: a
 * user who owns any company cannot delete their account until they transfer or
 * delete those companies.
 *
 * Deletion mirrors the operator path (phase 2.3 Admin\UserController::destroy):
 * a REVERSIBLE soft-delete via the `user_deleted_at` identity column, plus a
 * purge of the tracked sessions. This is the START of a grace period (phase 5.3,
 * decision D-5.3-3): the account is hidden and signed out immediately, and the
 * `larafoundry:purge-deleted-accounts` command irreversibly anonymises it once
 * the grace window elapses (a super-admin can still restore it until then). The
 * personal-data export and the symmetric purge registry are the other halves of
 * phase 5.3.
 */
class DeleteUserAccount
{
    /**
     * @throws ValidationException when the user still owns a company.
     */
    public function delete(Authenticatable $user): void
    {
        if ($this->ownsCompany($user)) {
            throw ValidationException::withMessages([
                'account' => __('larafoundry::profile.account.owns_company'),
            ]);
        }

        if (method_exists($user, 'sessions')) {
            $user->sessions()->delete();
        }

        /** @var Model $user */
        $user->forceFill(['user_deleted_at' => now()])->save();

        // Audited like the operator delete, so a self-deletion is traceable. The
        // causer is the user themselves (still authenticated at this point);
        // `purge_after` records when the irreversible erasure becomes due.
        Activity::log(
            description: 'account.self_deleted',
            logName: 'default',
            properties: [
                'user_id' => $user->getKey(),
                'purge_after' => now()->addDays((int) config('larafoundry-legal.erasure.grace_days', 30))->toIso8601String(),
            ],
            subject: $user,
            geoSync: false,
        );
    }

    /**
     * Whether the user owns at least one company (would be orphaned by deletion).
     *
     * Guards on method existence so the action is safe in `personal` mode and on
     * a host model that does not compose the tenancy trait.
     */
    protected function ownsCompany(Authenticatable $user): bool
    {
        return method_exists($user, 'ownedCompanies')
            && $user->ownedCompanies()->exists();
    }
}
