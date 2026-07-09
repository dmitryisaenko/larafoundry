<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\RejectRemovalRequestAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\RemoveEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyArchived;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalRejected;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationAccepted;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\CompanyCreatedNotification;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\CompanyDeletedConfirmationNotification;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\EmployeeJoinedConfirmationNotification;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\EmployeeRemovedNotification;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\InvitationAcceptedOwnerNotification;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\InvitationRejectedOwnerNotification;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The phase-2a owner-employee lifecycle wiring, asserted against the Part-6
 * channel matrix: each action creates the right in-app notification row(s) for
 * the right recipient(s), and sends an HTML-template email only where the matrix
 * marks one. The master switch silences the whole set.
 */

function lnUser(string $email, ?string $locale = null): User
{
    return User::create([
        'name' => ucfirst(explode('@', $email)[0]),
        'lastname' => 'Doe',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'locale' => $locale,
    ]);
}

function lnCompany(User $owner, string $name = 'Acme'): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    return $company;
}

/**
 * @return array<int, Notification>
 */
function notificationsWith(string $titleKey): array
{
    return Notification::query()->where('title_key', $titleKey)->get()->all();
}

function notificationForUser(string $titleKey, int $userId): ?Notification
{
    return Notification::query()
        ->where('title_key', $titleKey)
        ->whereHas('users', fn ($q) => $q->where('users.id', $userId))
        ->first();
}

// ---------------------------------------------------------------------------
// Row 4 — member requested removal: owner + member in-app, no email.
// ---------------------------------------------------------------------------
it('row 4: request-removal notifies owner and member in-app, no email', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($employee)->post('/employees/request-removal')->assertRedirect();

    $ownerNotif = notificationForUser('larafoundry::notifications.tenancy.removal_requested.owner.title', $owner->id);
    $userNotif = notificationForUser('larafoundry::notifications.tenancy.removal_requested.user.title', $employee->id);

    expect($ownerNotif)->not->toBeNull()
        ->and($ownerNotif->params['company'])->toBe('Acme')
        ->and($userNotif)->not->toBeNull();

    NotificationFacade::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Row 4.3 — member withdrew request: owner + member in-app, no email.
// ---------------------------------------------------------------------------
it('row 4.3: cancel-removal notifies owner and member in-app, no email', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);
    $company->users()->updateExistingPivot($employee->id, ['removal_requested_at' => now(), 'removal_requested_by' => $employee->id]);

    $this->actingAs($employee)->post('/employees/cancel-removal')->assertRedirect();

    expect(notificationForUser('larafoundry::notifications.tenancy.removal_cancelled.owner.title', $owner->id))->not->toBeNull()
        ->and(notificationForUser('larafoundry::notifications.tenancy.removal_cancelled.user.title', $employee->id))->not->toBeNull();
    NotificationFacade::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Row 3 vs 4.1 — removal without / with a pending request.
// ---------------------------------------------------------------------------
it('row 3: plain removal notifies both in-app but sends NO member email', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    app(RemoveEmployeeAction::class)->execute($company, $employee);

    expect(notificationForUser('larafoundry::notifications.tenancy.removed.owner.title', $owner->id))->not->toBeNull()
        ->and(notificationForUser('larafoundry::notifications.tenancy.removed.user.title', $employee->id))->not->toBeNull();
    NotificationFacade::assertNothingSent();
});

it('row 4.1: approved removal (pending request) emails the member employee_removed_notification', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);
    $company->users()->updateExistingPivot($employee->id, ['removal_requested_at' => now(), 'removal_requested_by' => $employee->id]);

    app(RemoveEmployeeAction::class)->execute($company, $employee->fresh());

    expect(notificationForUser('larafoundry::notifications.tenancy.removed.user.title', $employee->id))->not->toBeNull();
    NotificationFacade::assertSentTo($employee, EmployeeRemovedNotification::class);
});

// ---------------------------------------------------------------------------
// Row 4.2 — owner rejects a removal request (the new feature).
// ---------------------------------------------------------------------------
it('row 4.2: rejectRemoval clears the pivot, keeps the member, fires the event, notifies both, no email, logs activity', function () {
    NotificationFacade::fake();
    Event::fake([EmployeeRemovalRejected::class]);

    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);
    $company->users()->updateExistingPivot($employee->id, ['removal_requested_at' => now(), 'removal_requested_by' => $employee->id]);

    $this->actingAs($owner)->post('/employees/reject-removal', ['user_id' => $employee->id])->assertRedirect();

    // Event fired (feeds the activity log via the registry).
    Event::assertDispatched(EmployeeRemovalRejected::class);

    // Pivot cleared, membership intact.
    $row = DB::table('company_user')->where('company_id', $company->id)->where('user_id', $employee->id)->first();
    expect($row->removal_requested_at)->toBeNull()
        ->and($row->removal_requested_by)->toBeNull()
        ->and((bool) $row->is_deleted)->toBeFalse();
});

it('row 4.2: notifies owner and member in-app and sends no email (no Event fake)', function () {
    NotificationFacade::fake();

    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);
    $company->users()->updateExistingPivot($employee->id, ['removal_requested_at' => now(), 'removal_requested_by' => $employee->id]);

    $this->actingAs($owner)->post('/employees/reject-removal', ['user_id' => $employee->id])->assertRedirect();

    expect(notificationForUser('larafoundry::notifications.tenancy.removal_rejected.owner.title', $owner->id))->not->toBeNull()
        ->and(notificationForUser('larafoundry::notifications.tenancy.removal_rejected.user.title', $employee->id))->not->toBeNull();
    NotificationFacade::assertNothingSent();
});

it('row 4.2 activity: the rejection is written to the activity log', function () {
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);
    $company->users()->updateExistingPivot($employee->id, ['removal_requested_at' => now(), 'removal_requested_by' => $employee->id]);

    $this->actingAs($owner)->post('/employees/reject-removal', ['user_id' => $employee->id])->assertRedirect();

    expect(Activity::query()->where('event', 'EmployeeRemovalRejected')->count())->toBe(1);
});

it('row 4.2 SECURITY: a non-owner member cannot reject a removal request (403)', function () {
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $requester = lnUser('req@x.test');
    $company->addEmployee($requester, addedById: $owner->id);
    $company->users()->updateExistingPivot($requester->id, ['removal_requested_at' => now(), 'removal_requested_by' => $requester->id]);

    // A different, non-owner member tries to reject.
    $other = lnUser('other@x.test');
    $company->addEmployee($other, addedById: $owner->id);

    $this->actingAs($other)->post('/employees/reject-removal', ['user_id' => $requester->id])->assertForbidden();
});

it('row 4.2 SECURITY: cannot reject for a user outside the company (IDOR -> 404)', function () {
    $owner = lnUser('owner@x.test');
    lnCompany($owner);

    // A user who is not a member of the owner's company.
    $outsider = lnUser('outsider@x.test');

    $this->actingAs($owner)->post('/employees/reject-removal', ['user_id' => $outsider->id])->assertNotFound();
});

it('row 4.2: rejecting when there is no pending request 404s', function () {
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($owner)->post('/employees/reject-removal', ['user_id' => $employee->id])->assertNotFound();
});

it('row 4.2: the action is idempotent and dispatches no event with no pending request', function () {
    Event::fake([EmployeeRemovalRejected::class]);
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    app(RejectRemovalRequestAction::class)->execute($company, $employee);

    Event::assertNotDispatched(EmployeeRemovalRejected::class);
});

// ---------------------------------------------------------------------------
// Rows 1 / 2 — invite (owner always; registered invitee gets a user in-app).
// ---------------------------------------------------------------------------
it('row 1: inviting an UNREGISTERED email notifies the owner only, no user in-app', function () {
    $owner = lnUser('owner@x.test');
    lnCompany($owner);

    $this->actingAs($owner)->post('/employees/invite', ['email' => 'newbie@x.test'])->assertRedirect();

    expect(notificationsWith('larafoundry::notifications.tenancy.invited.owner.title'))->toHaveCount(1)
        ->and(notificationsWith('larafoundry::notifications.tenancy.invited.user.title'))->toHaveCount(0);
});

it('row 2: inviting a REGISTERED user notifies both owner and that user in-app', function () {
    $owner = lnUser('owner@x.test');
    lnCompany($owner);
    $existing = lnUser('existing@x.test');

    $this->actingAs($owner)->post('/employees/invite', ['email' => 'existing@x.test'])->assertRedirect();

    expect(notificationForUser('larafoundry::notifications.tenancy.invited.owner.title', $owner->id))->not->toBeNull()
        ->and(notificationForUser('larafoundry::notifications.tenancy.invited.user.title', $existing->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Row 2.1 — ALREADY-REGISTERED invitee accepted (wasNewAccount=false): owner
// in-app + owner email + member in-app, but NO member email.
// ---------------------------------------------------------------------------
it('row 2.1: an already-registered user accepting gets NO joined email, but owner + both in-app fire', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    // Pre-existing account: created in a prior request, so wasRecentlyCreated is
    // false when the HTTP accept runs -> wasNewAccount false.
    $invitee = lnUser('joiner@x.test');

    $invitation = $company->invitations()->create([
        'email' => 'joiner@x.test',
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($invitee)->post("/invitations/{$invitation->token}/accept")->assertRedirect();

    expect(notificationForUser('larafoundry::notifications.tenancy.accepted.owner.title', $owner->id))->not->toBeNull()
        ->and(notificationForUser('larafoundry::notifications.tenancy.accepted.user.title', $invitee->id))->not->toBeNull();
    // Owner email fires on both rows; the member gets NO joined-confirmation email.
    NotificationFacade::assertSentTo($owner, InvitationAcceptedOwnerNotification::class);
    NotificationFacade::assertNotSentTo($invitee, EmployeeJoinedConfirmationNotification::class);
});

// ---------------------------------------------------------------------------
// Row 1.1 — UNREGISTERED invitee who registered as part of accepting
// (wasNewAccount=true): owner in-app + owner email + member in-app + member email.
// ---------------------------------------------------------------------------
it('row 1.1: a freshly registered invitee gets the joined email plus member in-app and the owner channels', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $invitee = lnUser('newbie@x.test');
    $company->addEmployee($invitee, addedById: $owner->id);

    $invitation = $company->invitations()->create([
        'email' => 'newbie@x.test',
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_ACCEPTED,
        'accepted_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    // Drive the event with wasNewAccount=true (the unregistered-then-registered
    // path); the HTTP route sets this from $user->wasRecentlyCreated.
    InvitationAccepted::dispatch($invitation, $invitee, true);

    expect(notificationForUser('larafoundry::notifications.tenancy.accepted.owner.title', $owner->id))->not->toBeNull()
        ->and(notificationForUser('larafoundry::notifications.tenancy.accepted.user.title', $invitee->id))->not->toBeNull();
    NotificationFacade::assertSentTo($owner, InvitationAcceptedOwnerNotification::class);
    NotificationFacade::assertSentTo($invitee, EmployeeJoinedConfirmationNotification::class);
});

// ---------------------------------------------------------------------------
// Row 2.2 — registered invitee rejected: owner in-app + owner email + user in-app.
// ---------------------------------------------------------------------------
it('row 2.2: rejecting an invitation emails the owner and notifies owner + user in-app', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $invitee = lnUser('decliner@x.test');

    $invitation = $company->invitations()->create([
        'email' => 'decliner@x.test',
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($invitee)->post("/invitations/{$invitation->token}/reject")->assertRedirect();

    expect(notificationForUser('larafoundry::notifications.tenancy.rejected.owner.title', $owner->id))->not->toBeNull()
        ->and(notificationForUser('larafoundry::notifications.tenancy.rejected.user.title', $invitee->id))->not->toBeNull();
    NotificationFacade::assertSentTo($owner, InvitationRejectedOwnerNotification::class);
});

// ---------------------------------------------------------------------------
// Row 5 — owner withdrew invitation: owner in-app only, no email.
// ---------------------------------------------------------------------------
it('row 5: withdrawing an invitation notifies the owner in-app only, no email', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $invitation = $company->invitations()->create([
        'email' => 'pending@x.test',
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($owner)->delete("/employees/invitations/{$invitation->id}")->assertRedirect();

    expect(notificationForUser('larafoundry::notifications.tenancy.invitation_withdrawn.owner.title', $owner->id))->not->toBeNull();
    NotificationFacade::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Row 7 — owner changed roles: member in-app only.
// ---------------------------------------------------------------------------
it('row 7: role change notifies only the affected member, not the owner', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $role = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $employee = rbacUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($owner)->post("/employees/{$employee->id}", [
        'name' => $employee->name,
        'lastname' => $employee->lastname,
        'manage_roles' => true,
        'role_ids' => [$role->id],
    ])->assertRedirect();

    expect(notificationForUser('larafoundry::notifications.tenancy.role_changed.user.title', $employee->id))->not->toBeNull()
        ->and(notificationForUser('larafoundry::notifications.tenancy.role_changed.user.title', $owner->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Row 8 — company created: owner in-app + owner email.
// ---------------------------------------------------------------------------
it('row 8: creating a company notifies + emails the owner', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');

    // Drive the event directly (the wizard controller path is heavier; the
    // listener is the unit under test).
    $company = lnCompany($owner, 'NewCo');
    CompanyCreated::dispatch($company, $owner);

    expect(notificationForUser('larafoundry::notifications.tenancy.company_created.owner.title', $owner->id))->not->toBeNull();
    NotificationFacade::assertSentTo($owner, CompanyCreatedNotification::class);
});

// ---------------------------------------------------------------------------
// Row 9 — company archived: owner in-app + owner email (archive wording).
// ---------------------------------------------------------------------------
it('row 9: archiving a company notifies + emails the owner', function () {
    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);

    CompanyArchived::dispatch($company);

    expect(notificationForUser('larafoundry::notifications.tenancy.company_archived.owner.title', $owner->id))->not->toBeNull();
    NotificationFacade::assertSentTo($owner, CompanyDeletedConfirmationNotification::class);
});

// ---------------------------------------------------------------------------
// Locale — owner email renders in the recipient's locale.
// ---------------------------------------------------------------------------
it('renders the owner email in the owner locale (uk vs en)', function () {
    $ukOwner = lnUser('uk@x.test', 'uk');
    $enOwner = lnUser('en@x.test', 'en');

    $ukMail = (new CompanyCreatedNotification('Uk Doe', 'UkCo'))->toMail($ukOwner);
    $enMail = (new CompanyCreatedNotification('En Doe', 'EnCo'))->toMail($enOwner);

    // The uk render uses the uk subject wording; the en render uses en.
    expect($ukMail->subject)->toContain('готова')
        ->and($enMail->subject)->toContain('is ready');
});

// ---------------------------------------------------------------------------
// Master switch off — no lifecycle notifications at all.
// ---------------------------------------------------------------------------
it('master switch off: no lifecycle in-app notifications are created', function () {
    // The listeners are already subscribed, but each one re-reads the master
    // switch at handle time, so flipping it off at runtime silences the whole set
    // (a host toggles the config; the provider-time skip is the same guard).
    config()->set('larafoundry-notifications.lifecycle.enabled', false);

    NotificationFacade::fake();
    $owner = lnUser('owner@x.test');
    $company = lnCompany($owner);
    $employee = lnUser('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($employee)->post('/employees/request-removal')->assertRedirect();

    expect(Notification::query()->where('title_key', 'like', 'larafoundry::notifications.tenancy.%')->count())->toBe(0);
    NotificationFacade::assertNothingSent();
});
