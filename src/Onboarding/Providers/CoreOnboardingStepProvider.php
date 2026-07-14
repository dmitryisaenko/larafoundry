<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Onboarding\Providers;

use Dmitryisaenko\LaraFoundry\Onboarding\Contracts\OnboardingStepProviderInterface;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The core's own getting-started steps (phase 5.4).
 *
 * Four gentle nudges every LaraFoundry app shares: complete your profile, secure
 * the account with 2FA, set up the company, invite a teammate. The last two are
 * teams-only — in personal mode there are no companies to set up or invite into,
 * so they are omitted here (NOT split into a second provider): a personal-mode
 * user simply sees the two identity steps, and once both are done the checklist
 * hides itself.
 *
 * Completion is read from the user's real state (name/lastname, Fortify 2FA,
 * active company setup + membership), never a stored flag — so a step un-completes
 * if the user later clears it, and the checklist is always honest.
 */
class CoreOnboardingStepProvider implements OnboardingStepProviderInterface
{
    public function supports(): bool
    {
        return true;
    }

    public function priority(): int
    {
        return 100;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function steps(Authenticatable $user): array
    {
        $steps = [
            [
                'key' => 'profile',
                'title' => 'Complete your profile',
                'description' => 'Add your name so teammates recognise you.',
                'cta_route' => 'profile.index',
                'cta_label' => 'Go to profile',
                'complete' => $this->profileComplete($user),
                'order' => 10,
            ],
            [
                'key' => 'two_factor',
                'title' => 'Secure your account',
                'description' => 'Turn on two-factor authentication.',
                'cta_route' => 'profile.index',
                'cta_label' => 'Enable 2FA',
                'complete' => $this->twoFactorComplete($user),
                'order' => 20,
            ],
        ];

        // Teams-only steps: a personal-mode app has no companies, so setting one
        // up or inviting into it are not steps that exist there.
        if (config('larafoundry.tenancy.mode') !== 'personal') {
            $company = $this->activeCompany($user);

            $steps[] = [
                'key' => 'company_setup',
                'title' => 'Set up your company',
                'description' => 'Add your company details.',
                'cta_route' => 'settings.company',
                'cta_label' => 'Set up company',
                'complete' => $company !== null && ! $company->isInSetup(),
                'order' => 30,
            ];

            $steps[] = [
                'key' => 'invite_teammate',
                'title' => 'Invite a teammate',
                'description' => 'Add someone to your company.',
                'cta_route' => 'tenancy.employees.index',
                'cta_label' => 'Invite',
                'complete' => $this->hasTeammate($company),
                'order' => 40,
            ];
        }

        return $steps;
    }

    /**
     * The profile step is done once the user carries both a first and last name —
     * the minimum for a teammate to recognise them.
     */
    protected function profileComplete(Authenticatable $user): bool
    {
        // Attributes live on the Eloquent user model; guard the contract type so a
        // non-model authenticatable degrades to "not done" rather than erroring.
        if (! $user instanceof Model) {
            return false;
        }

        $name = trim((string) $user->getAttribute('name'));
        $lastname = trim((string) $user->getAttribute('lastname'));

        return $name !== '' && $lastname !== '';
    }

    /**
     * The 2FA step reads Fortify's confirmed-2FA state. Guarded with
     * method_exists so a host model that (unusually) omits the trait degrades to
     * "not done" rather than erroring.
     */
    protected function twoFactorComplete(Authenticatable $user): bool
    {
        return method_exists($user, 'hasEnabledTwoFactorAuthentication')
            && $user->hasEnabledTwoFactorAuthentication();
    }

    protected function activeCompany(Authenticatable $user): ?Company
    {
        if (! method_exists($user, 'getActiveCompany')) {
            return null;
        }

        $company = $user->getActiveCompany();

        return $company instanceof Company ? $company : null;
    }

    /**
     * The invite step is done once the company has a second member OR at least
     * one pending invitation — both mean the owner has reached out to someone.
     */
    protected function hasTeammate(?Company $company): bool
    {
        if ($company === null) {
            return false;
        }

        return $company->users()->count() > 1 || $company->invitations()->exists();
    }
}
