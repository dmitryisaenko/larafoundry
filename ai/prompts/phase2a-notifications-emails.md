# Prompt — Phase 2a: Notifications + Emails completeness (in-app wiring + missing templates + matrix row 4.2)

Created: 2026-07-09 17:19. Plan: `foundry/ai/admin-core-vs-legacy-gap.md` (Part 3.D + Part 5 phase 2 + **Part 6 owner-employee channel matrix — authoritative**).
Goal: wire in-app notifications and transactional emails across the owner-employee lifecycle **strictly per the Part 6 matrix**,
add the missing email templates, and build the one missing feature (matrix row 4.2: owner rejects a removal request).
Backend-heavy. The events already exist (v0.28.0) but are audit-only — Phase 2a adds **listeners** that push notifications/emails.

**Follows the CRUD email-template editor into Phase 2b** (separate prompt). This prompt does NOT touch the editor UI or add a `type` field.

---

## Ground truth (verified in code — do NOT re-derive, build on this)

**In-app infra is ready** — one seam, already used only by tickets:
`NotificationService::system(iterable $users, string $code, string $titleKey, ?string $bodyKey = null, array $params = [], array $data = [], bool $mail = false): Notification`
(`src/Notifications/Support/NotificationService.php:37`). Creates one `larafoundry_notifications` row (type `system`, translation keys, per-locale at read time) + attaches recipients on pivot. `$mail=true` additionally queues per-recipient email **via the plain-line `InAppMailNotification`** (a `MailMessage->line()`, NOT the HTML template registry). Reference call site: `Tickets\Http\Controllers\Admin\TicketController::notifyAuthor` (line 258/274).

**TWO decoupled mail systems** (critical):
- (a) **HTML transactional templates** — `EmailTemplateRepository` + `config/larafoundry-email.php`. Editable subject/body per-locale, `{{var}}` strict whitelist, sanitized, DB-overridable. Sent via `EmailTemplateRepository::mailMessage($code, $locale, $data): ?MailMessage` with a localized static fallback when null. The 4 existing codes use this. **All new transactional lifecycle emails in this phase MUST use this HTML registry**, mirroring `CompanyInvitationNotification`.
- (b) **plain-line `InAppMailNotification`** — the `mail:true` arm of `system()`. Short notices only, no `code`, no HTML template. Do NOT route the new lifecycle *emails* through this — they are proper transactional templates (a).

So the pattern per lifecycle action = **two independent channels**: in-app via `system(..., mail:false)`, and (where the matrix marks an email) a dedicated Notification class rendering an HTML registry template. Do not conflate them.

**Events already dispatched (v0.28.0), currently no listeners** — all in `src/Tenancy/Events/`:
`CompanyCreated`, `CompanyArchived`, `CompanyUnarchived`, `CompanyInvitationSent`, `InvitationAccepted`, `InvitationRejected`, `InvitationWithdrawn`, `InvitationResent`, `EmployeeRemovalRequested`, `EmployeeRemovalCancelled`, `EmployeeRoleChanged`, `EmployeeRemoved`.
Each carries the models the listeners need (see recon; e.g. `InvitationAccepted` = CompanyInvitation + user; `EmployeeRemovalRequested` = Company + employee).

**Removal-request DB mechanism:** two nullable pivot columns on `company_user` — `removal_requested_at`, `removal_requested_by`. Set in `EmployeeController::requestRemoval` (updateExistingPivot), cleared to null in `cancelRemoval`. Helper `hasPendingRemoval(Company, Authenticatable): bool` reads `removal_requested_at !== null`.

**Owner of a company** — resolve via the existing owner relation/helper on `Company` (the pivot `is_owner`, used by `ownedActiveCompany()`). Notifications to "owner" target that user.

**Recipient locale** — always `$notifiable->locale ?? <fallback>`; in-app text is locale-resolved at read time from keys. Email renders in recipient locale via the registry.

---

## The authoritative matrix (Part 6) — what each row must emit

Legend: OwnerNotif = in-app to owner; OwnerEmail = HTML template to owner; UserNotif = in-app to employee/invitee; UserEmail = HTML template to invitee/employee; Event = already done (v0.28.0); Flash = already present in controllers (verify, don't duplicate).

| # | Action / trigger | OwnerNotif | OwnerEmail | UserNotif | UserEmail | Wire in |
|---|------------------|:-:|:-:|:-:|:-:|---|
| 1 | Owner invited UNREGISTERED | + | | | + (already: `company_invitation`) | invite/store — OwnerNotif new; user email exists |
| 1.1 | Unregistered accepted | + | + | | + | `InvitationAccepted` listener |
| 2 | Owner invited REGISTERED | + | | + | | invite/store — OwnerNotif + UserNotif new |
| 2.1 | Registered accepted | + | + | + | | `InvitationAccepted` listener |
| 2.2 | Registered rejected | + | + | + | | `InvitationRejected` listener |
| 3 | Owner removed employee | + | | + | | `EmployeeRemoved` listener |
| 4 | Employee requested removal | + | | + | | `EmployeeRemovalRequested` listener |
| 4.1 | Owner approved request (= removeEmployee) | + | | + | + | `EmployeeRemoved` listener (+ user email here) |
| 4.2 | Owner REJECTED request | + | | + | | **NEW action+event** (see below) |
| 4.3 | Employee withdrew request | + | | + | | `EmployeeRemovalCancelled` listener |
| 5 | Owner withdrew invitation | + | | | | `InvitationWithdrawn` listener |
| 6 | Owner re-invited | + | | | + | `InvitationResent` listener (user email = resend the invitation template) |
| 7 | Owner changed role/rights | | | + | | `EmployeeRoleChanged` listener |
| 8 | User created company | + | + | n/a | n/a | `CompanyCreated` listener (owner = the creator) |
| 9 | Owner "deleted" company (= archive) | + | + | n/a | n/a | `CompanyArchived` listener |

Rows 10.x (payment) are ADDON, not this phase.

**Disambiguation for rows 1/2 (invite) and 4/4.1:**
- Invite does not yet distinguish "unregistered vs registered" for notification purposes — the OwnerNotif fires on invite regardless; UserNotif (row 2) fires only when the invitee already has an account (invite an existing user → in-app to them). Determine "registered" by looking up a user with the invited email. If unregistered, only the existing `company_invitation` email goes out (already wired) — no UserNotif.
- Row 4.1 "owner approved" == the existing `removeEmployee` path (owner-initiated removal that happens to satisfy a pending request). Per matrix, 4.1 additionally sends **UserEmail** (`employee_removed_notification`) whereas plain row-3 removal does not. Distinguish: if the removed employee had `removal_requested_at !== null` at removal time → it's an approval (4.1) → send the user email; otherwise row-3 (no user email). Capture the pending flag in the `EmployeeRemoved` event or read it before removal and carry it on the event. **Prefer carrying a `wasRequested` bool on `EmployeeRemoved`** (add to the event; keep backward-compatible default false) so the listener can branch without a DB re-read.

---

## Deliverables

### A. Missing email templates (HTML registry — `config/larafoundry-email.php` + repo sample data)

Add these codes with en+uk subject/body_html/body_text + `variables` whitelist + `sampleData()` entries (mirror `company_invitation`). Keep copy short, professional, brand-neutral (host sets brand). **No em-dash in any UI/email copy — hyphen only.**

| Code | To | Trigger row | Key variables |
|------|----|-------------|---------------|
| `invitation_accepted_owner` | owner | 1.1, 2.1 | app_name, owner_name, member_name, company_name |
| `invitation_rejected_owner` | owner | 2.2 | app_name, owner_name, invited_email, company_name |
| `employee_removed_notification` | employee | 4.1 | app_name, member_name, company_name |
| `company_created` | owner | 8 | app_name, owner_name, company_name |
| `company_deleted_confirmation` | owner | 9 (= archived) | app_name, owner_name, company_name |

Notes: row 6 user email reuses the existing `company_invitation` template (resend = same template). Row 1.1/2.1 owner email = `invitation_accepted_owner`. `company_deleted_confirmation` copy = "archived" wording (recoverable), NOT permanent-delete — matches the archive semantics. `payment_*` templates are ADDON, excluded.

### B. In-app + email listeners (one listener per event, thin, testable)

Create listeners in `src/Tenancy/Listeners/` (new dir), subscribe them (either self-registered `subscribe()` like the activity-log listener, or explicit binding in the service provider — follow the existing pattern used for `LogRegisteredEvents`). Each listener:
- pushes in-app via `NotificationService::system(recipients, code:'info', titleKey, bodyKey, params, data, mail:false)` with translation keys under a new `larafoundry::notifications.tenancy.<action>.{title,body}` namespace (add to `lang/*/notifications.php` en+uk);
- where the matrix marks an email, sends the corresponding HTML-registry template via a dedicated Notification class (mirror `CompanyInvitationNotification` → `EmailTemplateRepository::mailMessage` + localized fallback), routed to the recipient (registered user = `$user->notify(...)`; unregistered invitee = `Notification::route('mail', $email)`).

Respect a master switch: gate the whole lifecycle-notification wiring behind a config flag (e.g. `larafoundry-notifications.lifecycle.enabled`, default true) mirroring the tickets `notifications.enabled` gate, so a host can silence it.

`data.actions` for in-app: give a relevant deep link where one exists (e.g. owner → employees page `route('tenancy.employees.index')`; invitee accepted → home). Reuse the tickets `data.actions` shape.

**Idempotency / no double-send:** listeners fire off already-guarded events (the controllers only dispatch on a real transition). Do not add extra sends. Row 1/2 owner-notif must fire once per invite, not per matrix-row interpretation.

### C. Feature gap — matrix row 4.2: owner rejects a removal request

Build the missing owner-side resolution (member stays):
- `RejectRemovalRequestAction::execute(Company $company, Authenticatable $employee): void` in `src/Tenancy/Actions/` — verify a pending request exists (`removal_requested_at !== null`, else no-op or 404 at controller), clear BOTH pivot columns to null via `updateExistingPivot` (mirror `cancelRemoval` lines 238-241), dispatch new event `EmployeeRemovalRejected` (Company + employee; `getLogProperties`: company_id/uuid, employee_id).
- Controller method `EmployeeController::rejectRemoval` (owner-only via `ownedActiveCompany()`, anti-IDOR resolve employee through the company's members, 403 if target is owner), returns `back()->with('status', __('larafoundry::tenancy.removal_rejected'))`. Add route mirroring `cancelRemoval`.
- Register `EmployeeRemovalRejected` in `config/larafoundry-activitylog.php` (group 'Tenancy', code 200) — this closes the one Phase-1 activity gap too.
- Listener for it per matrix row 4.2 (OwnerNotif + UserNotif, no email).
- Add `tenancy.removal_rejected` flash string (en+uk).

---

## Tests (Pest, per deliverable)

- **Templates:** each new code renders non-null for en+uk with sample data; `variables` whitelist enforced; inactive → null (fallback path).
- **Listeners:** driving each route/action creates the expected `larafoundry_notifications` row(s) attached to the right recipient(s) with the right keys; where an email is expected, assert the Notification was sent (`Notification::fake` + `assertSentTo` / `assertSentOnDemand`) with the right template code/recipient. Assert the NON-email rows do NOT email (matrix negative cases — e.g. row 3 no user email, row 4.1 yes).
- **Row 4.1 vs 3 branch:** removal with a pending request sends `employee_removed_notification` to the user; removal without does not.
- **4.2:** rejectRemoval clears pivot, member remains, fires `EmployeeRemovalRejected`, logs activity, notifies both, no email. Owner-only (non-owner 403). IDOR: cannot reject for a user outside the company.
- **Locale:** an owner with uk locale gets uk-rendered email/in-app; en owner gets en.
- **Master switch off** → no lifecycle notifications sent.
- Assert persisted payload where writes happen (row created + pivot attach + pivot cleared), not just redirect. Don't break existing tenancy/notification/ticket tests.

---

## Constraints & review gate

- No em-dash in any visible copy (labels/translations/email) — hyphen only.
- Follow ai_b.md patterns (Actions, FormRequests, thin controllers, Notifications).
- After green (Pest + Pint): `/security-review` + `/code-review` on the diff (2 finder agents), fix real findings, then hand Dmitry the commit name. **No tag on 2a** — one tag `v0.29.0` at the end of the core part (2a + 2b), per git-policy p.16.
- Git is Dmitry's: agent runs tests/lint to green and provides the commit name only. Never commit/tag/push.
- Suggested commit name:
  `Wire owner-employee lifecycle notifications and emails per channel matrix; add removal-request rejection`
