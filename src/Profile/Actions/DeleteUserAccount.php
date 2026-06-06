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
 * purge of the tracked sessions. True erasure / anonymisation and the personal-
 * data export are phase 5.3 — this action is their wiring point (the
 * UserDataExportRegistry seam runs here before deletion in that phase).
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
        // causer is the user themselves (still authenticated at this point).
        Activity::log(
            description: 'account.self_deleted',
            logName: 'default',
            properties: ['user_id' => $user->getKey()],
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
