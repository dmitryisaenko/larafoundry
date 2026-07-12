<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

/*
 * The FLASH column of the Part-6 relationship matrix: every owner-employee action
 * lands a one-shot flash (a toast) in the session for the ACTOR who made the HTTP
 * request. The lifecycle notification suite covers in-app + email per row; this
 * file covers the flash the donor matrix specifies but that suite never asserted.
 *
 * Flash is request-scoped, so it belongs to whoever hit the route — the owner for
 * owner-driven actions, the member for self-service ones. Each case asserts both
 * the presence and the exact i18n key (via assertSessionHas).
 *
 * Uses the autoloaded rbac* helpers (composer autoload-dev files); local helpers
 * are prefixed `flash*` so they never collide across the suite.
 */

beforeEach(function () {
    NotificationFacade::fake();
});

function flashInvite(object $company, string $email): CompanyInvitation
{
    return $company->invitations()->create([
        'email' => $email,
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ]);
}

// Row 1 / 2 — owner invites: flash to the OWNER.
it('row 1/2: inviting flashes invitation_sent to the owner', function () {
    $owner = rbacUser('owner@x.test');
    rbacCompany($owner);

    $this->actingAs($owner)
        ->post('/employees/invite', ['email' => 'newbie@x.test'])
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.invitation_sent'));
});

// Row 1.1 / 2.1 — invitee accepts: flash to the INVITEE.
it('row 1.1/2.1: accepting flashes invitation.accepted to the invitee', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $invitee = rbacUser('joiner@x.test');
    $invitation = flashInvite($company, 'joiner@x.test');

    $this->actingAs($invitee)
        ->post("/invitations/{$invitation->token}/accept")
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.invitation.accepted', ['company' => $company->name]));
});

// Row 2.2 — invitee rejects: flash to the INVITEE.
it('row 2.2: rejecting an invitation flashes invitation.rejected to the invitee', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $invitee = rbacUser('decliner@x.test');
    $invitation = flashInvite($company, 'decliner@x.test');

    $this->actingAs($invitee)
        ->post("/invitations/{$invitation->token}/reject")
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.invitation.rejected'));
});

// Row 3 / 4.1 — owner removes a member: flash to the OWNER.
it('row 3/4.1: removing an employee flashes employee_removed to the owner', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $employee = rbacUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($owner)
        ->delete('/employees', ['user_id' => $employee->id])
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.employee_removed'));
});

// Row 4 — member requests removal: flash to the MEMBER.
it('row 4: request-removal flashes removal_requested to the member', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $employee = rbacUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($employee)
        ->post('/employees/request-removal')
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.removal_requested'));
});

// Row 4.2 — owner rejects a removal request: flash to the OWNER.
it('row 4.2: rejecting a removal request flashes removal_rejected to the owner', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $employee = rbacUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);
    $company->users()->updateExistingPivot($employee->id, ['removal_requested_at' => now(), 'removal_requested_by' => $employee->id]);

    $this->actingAs($owner)
        ->post('/employees/reject-removal', ['user_id' => $employee->id])
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.removal_rejected'));
});

// Row 4.3 — member cancels their own removal request: flash to the MEMBER.
it('row 4.3: cancel-removal flashes removal_cancelled to the member', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $employee = rbacUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);
    $company->users()->updateExistingPivot($employee->id, ['removal_requested_at' => now(), 'removal_requested_by' => $employee->id]);

    $this->actingAs($employee)
        ->post('/employees/cancel-removal')
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.removal_cancelled'));
});

// Row 5 — owner withdraws a pending invitation: flash to the OWNER.
it('row 5: withdrawing an invitation flashes invitation_revoked to the owner', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $invitation = flashInvite($company, 'pending@x.test');

    $this->actingAs($owner)
        ->delete("/employees/invitations/{$invitation->id}")
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.invitation_revoked'));
});

// Row 6 — owner resends an invitation: flash to the OWNER.
it('row 6: resending an invitation flashes invitation_sent to the owner', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $invitation = flashInvite($company, 'pending@x.test');

    $this->actingAs($owner)
        ->post("/employees/invitations/{$invitation->id}/resend")
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.invitation_sent'));
});

// Row 7 — owner changes a member's roles: flash to the OWNER.
it('row 7: changing a member role flashes employee_updated to the owner', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $role = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();
    $employee = rbacUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($owner)
        ->post("/employees/{$employee->id}", [
            'name' => $employee->name,
            'lastname' => $employee->lastname,
            'manage_roles' => true,
            'role_ids' => [$role->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', __('larafoundry::tenancy.employee_updated'));
});
