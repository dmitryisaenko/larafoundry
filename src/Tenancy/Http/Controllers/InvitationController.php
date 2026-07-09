<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationAccepted;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationRejected;
use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accept or reject an employee invitation by token (phase 1.2).
 *
 * The token is the only public handle, but it is NOT sufficient on its own.
 * Acceptance requires ALL of:
 *   - the invite is pending and unexpired,
 *   - the accepting account's email matches the invited email, AND
 *   - the accepting account has a VERIFIED email.
 *
 * The verified check is the load-bearing anti-spoof guard: without it, an
 * attacker who learns a token could register a fresh, UNverified account using
 * the victim's email (local registration leaves email_verified_at null), pass
 * the email-string match, and join the company. Requiring a verified mailbox
 * means the accepting account has actually proven control of that address.
 *
 * The show page is reachable while logged out so the invitee can register/log in
 * first, but it deliberately does NOT reveal the invited email address (that
 * would hand an attacker the exact address to register).
 */
class InvitationController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = CompanyInvitation::with('company')->where('token', $token)->first();

        if ($invitation === null || ! $invitation->isActionable()) {
            return redirect('/')->with('error', __('larafoundry::tenancy.invitation.invalid'));
        }

        // The invited email is intentionally NOT exposed here: this route is
        // public, and leaking the address would tell an attacker holding the
        // token exactly which email to register to satisfy the accept guard.
        return Inertia::render('Tenancy/AcceptInvitation', [
            'token' => $invitation->token,
            'company' => $invitation->company->name,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->actionableOrFail($token);
        $user = $request->user();

        // Anti-spoof, two parts: the accepting account must own the invited
        // address (string match) AND have proven control of it (verified email).
        // The verified check is what makes a leaked token safe — a fresh
        // unverified account on the victim's email cannot satisfy it.
        if (! $invitation->isFor($user->email) || ! $this->hasVerifiedEmail($user)) {
            return redirect('/')->with('error', __('larafoundry::tenancy.invitation.email_mismatch'));
        }

        DB::transaction(function () use ($invitation, $user) {
            $invitation->company->addEmployee($user, addedById: $invitation->invited_by);

            $this->assignInvitedRole($invitation, $user);

            $invitation->update([
                'status' => CompanyInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
            ]);
        });

        if (method_exists($user, 'setActiveCompany')) {
            $user->setActiveCompany($invitation->company);
        }

        // Row 1.1 vs 2.1: the joined-confirmation email fires only for an
        // invitee who registered as part of this accept flow, not for an
        // already-registered user. Registration is a separate Fortify step in the
        // same request (an unregistered invitee registers, then lands here), so the
        // reliable in-request signal is Eloquent's wasRecentlyCreated — true when
        // the user row was inserted during THIS request, false for a pre-existing
        // account. This is the most reliable signal the accept path can see.
        $wasNewAccount = $user->wasRecentlyCreated ?? false;

        InvitationAccepted::dispatch($invitation, $user, $wasNewAccount);

        return redirect()->to(LaraFoundryTenancy::homeUrl())
            ->with('status', __('larafoundry::tenancy.invitation.accepted', [
                'company' => $invitation->company->name,
            ]));
    }

    /**
     * Grant the invitation's role-at-invite to the freshly joined user, if any.
     *
     * No-ops unless the invite carried a role that STILL exists and belongs to
     * this invitation's company — a role deleted between invite and accept (the
     * FK's nullOnDelete may also have already cleared role_id) simply yields no
     * grant, never an error. The company match is defence in depth on top of the
     * company-scoped invite-time validation. assignRole is idempotent, so a
     * re-accept is safe. Guarded by method_exists so a host User without the RBAC
     * trait still accepts invitations (just without the role grant).
     */
    protected function assignInvitedRole(CompanyInvitation $invitation, object $user): void
    {
        if ($invitation->role_id === null || ! method_exists($user, 'assignRole')) {
            return;
        }

        $role = $invitation->role;

        if ($role === null || (int) $role->company_id !== (int) $invitation->company_id) {
            return;
        }

        $user->assignRole($role, $invitation->company, assignedById: $invitation->invited_by);
    }

    public function reject(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->actionableOrFail($token);

        // Only the addressed user may reject (same anti-spoof reasoning).
        if (! $invitation->isFor($request->user()->email)) {
            return redirect('/')->with('error', __('larafoundry::tenancy.invitation.email_mismatch'));
        }

        $invitation->update(['status' => CompanyInvitation::STATUS_REJECTED]);

        InvitationRejected::dispatch($invitation, $request->user());

        return redirect('/')->with('status', __('larafoundry::tenancy.invitation.rejected'));
    }

    /**
     * Load a still-actionable invitation or abort with a flash redirect.
     */
    protected function actionableOrFail(string $token): CompanyInvitation
    {
        $invitation = CompanyInvitation::with('company')->where('token', $token)->first();

        abort_if($invitation === null || ! $invitation->isActionable(), 410);

        return $invitation;
    }

    /**
     * Whether the account has proven control of its email address.
     *
     * A host User that doesn't implement MustVerifyEmail (no verification flow)
     * is treated as verified — there is no mailbox-ownership signal to enforce,
     * so the email-string match is the only available guard for that host.
     */
    protected function hasVerifiedEmail(object $user): bool
    {
        return ! method_exists($user, 'hasVerifiedEmail') || $user->hasVerifiedEmail();
    }
}
