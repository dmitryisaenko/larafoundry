# Prompt — Phase 1: Activity completeness (admin-parity roadmap)

Created: 2026-07-09. Plan: `foundry/ai/admin-core-vs-legacy-gap.md` (Part 5 phase 1 + Part 6 matrix).
Goal: wire the missing lifecycle events into the activity-log registry so the audit trail matches the
owner-employee channel matrix. Backend-only. Events dispatch from EXISTING actions (no new tenancy features here).

Split into two commits:

## 1a — Tenancy owner-employee lifecycle events (matrix rows 1-7)
Create event classes (mirror `EmployeeRemoved`/`TicketCreated`: `Dispatchable`, `getLogProperties()`), dispatch at the
right site, register in `config/larafoundry-activitylog.php` (group 'Tenancy', code 200). Causer = auth user (listener resolves).

| Event | Dispatch site | Carries | getLogProperties |
|-------|---------------|---------|------------------|
| InvitationAccepted | InvitationController::accept (post-tx) | CompanyInvitation + user | company_id/uuid, invitation_id, invited_email |
| InvitationRejected | InvitationController::reject | CompanyInvitation + user | same |
| InvitationWithdrawn | EmployeeController::deleteInvitation (capture before delete) | CompanyInvitation | company_id/uuid, invitation_id, invited_email |
| InvitationResent | EmployeeController::resendInvitation | CompanyInvitation | same |
| EmployeeRemovalRequested | EmployeeController::requestRemoval | Company + employee | company_id/uuid, employee_id |
| EmployeeRemovalCancelled | EmployeeController::cancelRemoval | Company + employee | same |
| EmployeeRoleChanged | UpdateEmployeeAction (only when manage_roles AND role set changed) | Company + employee + role_ids | company_id/uuid, employee_id, role_ids |

Notes: dispatch InvitationWithdrawn BEFORE `->delete()` (capture id/email/company). EmployeeRoleChanged: capture role ids
before sync, compare after, fire only on real change (no noise). Pest per event: driving the route/action logs the entry
(assert Activity row event=ClassName, log_name='Tenancy', properties). Don't break existing tenancy tests.

## 1b — Roles CRUD + Profile/Password + dead-letters + admin audit (second commit)
- Roles: RoleCreated/RoleUpdated/RoleDeleted events from the role controller/actions (Authorization module) + register (group 'Authorization').
- Profile: ProfileUpdated (UpdateUserProfileInformation), PasswordUpdated (password update action) + register (group 'Profile' or 'Auth').
- Register the 2 dead-lettered events already in code: `AdminAccessAttemptFailed`, `Media\Events\FileUploaded`.
- Audit admin-editable screens with `Activity::log`: Settings / Legal pages / Email templates admin controllers (created/updated/deleted), logName 'admin'.

## Gate (both)
Pest/PHPUnit each, `vendor/bin/pint`, don't break admin/tenancy tests, `route:list --path=admin`. Tag by Dmitry (v0.28.0+).
Deferred to Phase 3 (feature, not event): matrix row 4.2 owner-reject-removal action → then EmployeeRemovalRejected event.
