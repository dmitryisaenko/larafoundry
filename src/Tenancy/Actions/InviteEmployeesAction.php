<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Actions;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyInvitationSent;
use Dmitryisaenko\LaraFoundry\Tenancy\Jobs\SendCompanyInvitationJob;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Support\Collection;

/**
 * Creates pending invitations for a set of emails and queues their delivery.
 *
 * Used by wizard step 3 and the standalone invite action. Each email gets a
 * strong unique token and an expiry from config. Already-pending invitations for
 * the same (company, email) are refreshed rather than duplicated (the unique
 * index forbids a second row), and existing members are skipped.
 *
 * @return Collection<int, CompanyInvitation>
 */
class InviteEmployeesAction
{
    /**
     * @param  array<int, string>  $emails
     * @return Collection<int, CompanyInvitation>
     */
    public function execute(Company $company, array $emails, ?int $invitedBy = null): Collection
    {
        $expiryDays = (int) config('larafoundry.tenancy.invitation_expiry_days', 7);

        // NB: wrap mb_strtolower in a closure — passing it as a string callable
        // to Collection::map leaks the item KEY as the $encoding argument.
        $memberEmails = $company->users()->pluck('email')
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();

        return collect($emails)
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->unique(fn (string $email) => mb_strtolower($email))
            ->reject(fn (string $email) => in_array(mb_strtolower($email), $memberEmails, true))
            ->map(function (string $email) use ($company, $expiryDays, $invitedBy): CompanyInvitation {
                $invitation = $company->invitations()->updateOrCreate(
                    ['email' => $email],
                    [
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
}
