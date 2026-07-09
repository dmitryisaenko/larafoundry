# Prompt — Admin base additions for Krokq (core /admin)

Created: 2026-07-09 11:37 (Get-Date). Executed: 2026-07-09.
Source: Dmitry's Krokq prompt, verified against core code + 2 corrections folded in (A, B below).

Goal: fill 5 missing "base" gaps in the core super-admin console `/admin`, reusable by any host.
All in core (`larafoundry`); host only consumes. Krokq's demo mechanism stays in host.

## Verified facts (checked in code before executing)
- `AdminUserResource` does NOT expose locale / OAuth type — P1 adds them.
- `AdminUsersFilter` has no `locale` / `authType` — P1 adds them; `UserController::index` `$request->only([...])` widened.
- User columns `locale`, `provider_name` exist in migration `2026_01_01_000100_add_larafoundry_columns_to_users_table`.
- No `admin/payments` route; billing seam (`PaymentGatewayInterface` + `NullGateway`) present — P2 is a pure stub.
- `ArchiveCompanyController` emits NO events — P3 adds them; activity-log registry is additive (Tenancy group).
- Table rendered by CORE component `UsersTable.vue` (not Index.vue). Columns + generic seam go THERE; filters in Index.vue.
- UI strings = English-as-key in `lang/frontend/{en,uk}.json` (vue-i18n). `lang/*/admin.php` = server flashes only.
- Admin nav = `Navigation/Providers/AdminMenuProvider.php`.

## CORRECTION A (P3 event model)
`CompanyCreated` is NOT the right template: it has no `getLogProperties()` and its ctor carries an `owner`.
Model the new events on `TicketCreated` / `FileUploaded` / `BroadcastNotificationSent` (they have `getLogProperties()`).
`CompanyArchived` / `CompanyUnarchived`: ctor `public readonly Company $company` only (spatie causer = auth user auto);
`getLogProperties()` -> `['company_id' => …, 'company_uuid' => …]`. Register in
`config/larafoundry-activitylog.php` under group 'Tenancy', code 200.

## CORRECTION B (P4 seam — close BOTH ends)
Host wants a "used demo?" column WITHOUT forking `Users/Index.vue`/`UsersTable.vue`.
Backend `extra()` alone gives data but not render. Ship a generic mechanism:
- Backend: `AdminUserResource` gains `protected extra(): array` returning extra cells; `UserController` resolves the
  resource class from `config('larafoundry.admin.user_resource', AdminUserResource::class)` so a host can subclass and
  override `extra()`. Resource emits `extra_columns` (array of cells: `{ key, label, value|badge }`).
- Frontend: `UsersTable.vue` renders `extra_columns` generically (extra <th> from the first row's keys + a cell per row)
  BEFORE the actions column, so a host gets its column with zero fork.
- Document the seam in `docs/integrating-into-an-existing-app.md`.

## The 5 points
1. locale + auth_type in resource/filter/controller + UsersTable columns + Index.vue filters + frontend lang en/uk.
2. Payments stub: route `admin.payments.index`, `PaymentController@index`, `Admin/Payments/Index.vue` empty state,
   nav item, frontend lang.
3. CompanyArchived/Unarchived events (see Correction A) + emit in archive()/unarchive() + registry.
4. user-column seam (see Correction B) + docs.
5. Pest/PHPUnit per piece, pint, lang en+uk, don't break admin tests, `route:list --path=admin`. Tag v0.27.0 by Dmitry.

## After core (host, not here)
Demo-apply log via `Activity::log()`; host demo column via the seam; bump core constraint in host composer.json.
