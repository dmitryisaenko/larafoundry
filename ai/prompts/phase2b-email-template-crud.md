# Prompt — Phase 2b: Full email-template CRUD editor (two-layer: transactional registry + marketing DB)

Created: 2026-07-09 22:16. Plan: `foundry/ai/admin-core-vs-legacy-gap.md` (Part 3.A + Part 4 decision #4 + Part 5 phase 2).
Goal: turn the edit-only email-template console into a **full CRUD** editor matching kohana_legacy — create / duplicate / delete /
test-send / template **types** — WITHOUT breaking the fail-closed guarantee of the transactional templates.
Backend + Vue (Inertia). Super-admin only, behind `larafoundry.admin` (+ OTP), each action re-checks the policy.

**Decisions locked (Dmitry, 2026-07-09):**
- **Two layers.** (a) **Transactional** = the config registry codes (`type=transactional`), code-driven — a Notification sends them by `code`. NOT deletable, NOT renamable, NOT creatable from the panel; you may only edit subject/body per-locale + toggle active (today's behaviour, unchanged). (b) **Marketing** = arbitrary operator-authored templates (`type=marketing`), pure DB entities, FULL create/duplicate/delete. The fail-closed integrity of transactional templates is preserved (a system code can never be deleted out from under its Notification).
- **Editor only.** Build create/duplicate/delete/edit/preview/test-send. Do NOT build bulk sending (segments/queue/unsubscribe/GDPR) — that is a separate large topic, out of scope. Marketing templates become ready to use; actual campaign send is later/host.

---

## Ground truth (verified — build on this, don't re-derive)

- **Registry** `config/larafoundry-email.php` — source of truth for transactional defaults + `variables` whitelist + rendering. After Phase 2a it holds **10 codes**: welcome_email, password_reset, email_verification, company_invitation, invitation_accepted_owner, invitation_rejected_owner, employee_removed_notification, company_created, company_deleted_confirmation, employee_joined_confirmation.
- **`EmailTemplateRepository`** (`src/Email/Support/EmailTemplateRepository.php`): `all()` (line ~96 — lists registry codes with `is_active`/`customized`), `find($code)` (120 — registry default + override merged), `render($code,$locale,$data)` (153 — returns rendered or null when unregistered/inactive), `mailMessage()` (184 — Notification seam), `save($code,$data)` (209 — **fail-closed: throws on unregistered code**, sanitizes html, updateOrCreate on override), `sampleData($code)` (241 — central `$known` map), `variablesFor`, `isRegistered`, `availableLocales`. Overrides cached `Cache::rememberForever('larafoundry.email_templates')`, busted on write.
- **`EmailTemplate` model** (`src/Email/Models/EmailTemplate.php`): thin OVERRIDE row — `fillable = code, subject_translations, body_html_translations, body_text_translations, is_active` (all JSON per-locale except code/bool). Table `larafoundry_email_templates`. Global (no company_id). **No `type`, no own `variables`, no `name`** today — a row is meaningless without its registry entry.
- **`EmailTemplateController`** (`src/Email/Http/Controllers/Admin/`): `index` (Inertia `Admin/EmailTemplates/Index`), `edit` (`Admin/EmailTemplates/Edit`), `update` (fail-closed via `isRegistered`), `preview` (JSON, sandboxed iframe), `sendTest` (rate-limited route). Audit `Activity::log(..., properties: ['template_code'=>...])` already added in Phase 1b (key is `template_code`, NOT `code`, to dodge `pii_redact_keys`).
- Vue pages: `resources/js/Pages/Admin/EmailTemplates/Index.vue` + `Edit.vue`. Routes in `routes/admin.php`.
- `TemplateRenderer` = strict `{{var}}` single-pass (no SSTI). `HtmlSanitizer::clean()` purifies on write AND on render (defence-in-depth).

---

## Deliverables

### A. Data model — add the marketing layer + `type`

Migration(s) on `larafoundry_email_templates` (do NOT migrate:fresh; write additive migrations):
- Add `type` string, default `'transactional'`, indexed. Existing rows (overrides of registry codes) = transactional.
- Add nullable `name` (operator-facing label, marketing only; transactional label comes from the registry code).
- Add nullable `variables` JSON (marketing templates carry their OWN whitelist; transactional read it from the registry).
- Keep `code` unique. Marketing codes are operator-generated slugs (validate: unique across BOTH registry codes and DB rows, lowercase/underscore, not colliding with any registry `code`).

Update `EmailTemplate` model: add the new fillable/casts; add a scope `marketing()` / `transactional()`; a helper `isTransactional()`. A marketing row is **self-contained** — it does NOT require a registry entry (this is the key difference; transactional rows still mirror a registry code).

**Repository changes** — teach it the two layers WITHOUT weakening fail-closed:
- `all()` must now return registry (transactional) entries AND marketing DB rows, each tagged with `type`, `name` (label), `is_active`, `customized`, and whether it is deletable (marketing = yes, transactional = no).
- `find($code)`: for a registry code → today's merge (registry default + override). For a marketing code (registered only in DB, not in config) → resolve entirely from the DB row (subject/body/variables/is_active). Return shape unchanged so the editor is uniform.
- `render()` / `mailMessage()`: work for marketing codes too (render from DB row), still returning null when inactive/missing. Transactional path unchanged. **Sanitize marketing html on render** as well.
- `variablesFor($code)`: registry code → registry whitelist; marketing code → the row's own `variables`.
- New methods: `createMarketing(array $data): EmailTemplate` (validates type=marketing, unique code, sanitizes html, sets own variables), `duplicate(string $sourceCode, array $overrides): EmailTemplate` (clones ANY template — registry or marketing — into a NEW marketing row with a fresh code+name; the copy is always marketing so a transactional code is never forked into a second code-driven sender), `deleteMarketing(string $code): void` (fail-closed: refuse to delete a transactional/registry code — throw or return false; only marketing rows deletable). Bust cache on every write.
- Keep `save()` fail-closed for transactional; marketing edits go through a marketing-aware save (or extend save to branch on type but NEVER allow creating/deleting a transactional code).

### B. Controller + routes — full CRUD

Extend `EmailTemplateController` (or a sibling controller) with:
- `create` (GET `Admin/EmailTemplates/Create` — marketing only) + `store` (POST) → `createMarketing`.
- `duplicate` (POST `/admin/email-templates/{code}/duplicate`) → `duplicate()`, redirects to edit the new marketing copy.
- `destroy` (DELETE `/admin/email-templates/{code}`) → `deleteMarketing`, **abort 403/404 if the code is transactional/registry** (defence-in-depth beyond the repo guard).
- `edit`/`update` unchanged for transactional; update must accept marketing rows too (edit name + variables + subject/body).
- `preview` + `sendTest` extended to marketing codes (sampleData for marketing = derive placeholders from the row's own `variables`, labelled sample per var like the existing `$known` fallback).
- FormRequests: `StoreMarketingEmailTemplateRequest` (code slug rules + uniqueness + name + variables + per-locale subject/body), reuse/extend `UpdateEmailTemplateRequest` with variable-whitelist enforcement that reads the RIGHT whitelist per type.
- Audit every mutating action via `Activity::log(logName:'admin', properties:['template_code'=>$code, 'type'=>...], geoSync:false)` — created/duplicated/deleted/updated. Use `template_code`, never `code`.
- Every action re-authorizes the super-admin policy. Rate-limit test-send (already on the route) and consider a light throttle on create/duplicate.

### C. Vue editor — CRUD UI

- `Index.vue`: show both layers, a `type` badge (Transactional / Marketing), a filter/tab by type. Marketing rows get Duplicate + Delete actions (with the CORE `confirm()` themed dialog — NOT native window.confirm, per project rule); transactional rows show only Edit (+ Duplicate → makes a marketing copy). "New template" button → Create (marketing).
- `Create.vue`: form for name, code (auto-slug from name, editable, validated), type=marketing (fixed for now), per-locale subject/body_html/body_text, a variables editor (add/remove `{{var}}` tokens), live preview (reuse the sandboxed-iframe preview seam), test-send.
- `Edit.vue`: for marketing, also edit name + variables; for transactional, unchanged (subject/body + active only, no code/name/delete). Show which variables are available (read-only for transactional).
- Sticky-head list table pattern if the list grows (TableCard + `.sticky-head`), per project convention. No em-dash in any visible copy — hyphen only. Use core ConfirmDialog for delete.

### D. i18n

Add en+uk keys for all new UI strings (labels, type badges, create/duplicate/delete confirmations, validation messages) under the email namespace. These will be mass-translated to the other 8 locales in Phase 6 — keep keys tidy.

---

## Tests (Pest)

- **Two layers:** `all()` returns 10 transactional (from registry) + N marketing (DB); each tagged correctly; deletable flag correct.
- **Fail-closed preserved:** cannot delete a transactional/registry code (repo throws AND controller 403/404); cannot create a marketing code that collides with a registry code or an existing marketing code; `save()` still throws on a truly unregistered non-marketing code.
- **Create marketing:** persists a self-contained row (own variables, own subject/body, type=marketing, is_active), renders non-null via `render()`/`mailMessage()` without any registry entry.
- **Duplicate:** duplicating a transactional code produces a NEW marketing row with a fresh code (original registry code untouched, still transactional, still sends via its Notification); duplicating a marketing row clones it.
- **Edit marketing:** name/variables/subject/body update; html sanitized on write; variable whitelist enforced (a body var outside the row's whitelist rejected by the FormRequest).
- **Delete marketing:** removes the row; `render()` then returns null.
- **Render/preview/test-send** work for marketing codes; sampleData derives from the row's variables.
- **Audit:** each mutation logs `admin.email_template.{created,duplicated,deleted,updated}` with readable `template_code` (assert NOT masked).
- **XSS:** a `<script>` in a marketing body is stripped on write and on render (assert clean).
- **Authorization:** all endpoints require super-admin (non-admin 403); OTP gate respected as elsewhere.
- Don't break the 910 existing tests (esp. the transactional send paths + Phase 2a lifecycle emails).
- Assert persisted payload (assertDatabaseHas) on create/duplicate/edit, not just redirect.

---

## Constraints & review gate

- Two decoupled mail systems remain (HTML registry vs plain-line InAppMailNotification) — this phase touches ONLY the HTML registry/DB layer. Do not touch the in-app path.
- No migrate:fresh / wipe on any dev DB. Additive migrations only. Tests use RefreshDatabase.
- No `npm run dev`; run `npm run build` once at the end (Vue changed this time).
- Core ConfirmDialog for destructive UI; no native confirm/alert. No em-dash in visible copy.
- Follow ai_b.md / ai_f.md patterns (Actions/FormRequests/Resources; Vue Composition API, SCSS).
- After green (Pest + Pint + build): `/security-review` + `/code-review` on the diff (2 finder agents), fix real findings, hand Dmitry the commit name.
- **This is the LAST core sub-phase of Phase 2. After 2b is committed, TAG `v0.29.0`** (covers 2a + 2b together) — but the tag is Dmitry's, agent never tags. Provide the commit name only.
- Suggested commit name:
  `Add full email-template CRUD editor with transactional/marketing template types`
