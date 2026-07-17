# Admin operator profile hub + list-hiding + sticky header

Создано: 2026-07-17 12:09
Выполнено: 2026-07-17 (агент реализовал все 4 задачи; 1134 Pest зелёные, Pint чист, ревью-гейт пройден; git — за Дмитрием)

Полировка ядра `larafoundry` перед переключением на host. Четыре правки. Ядро правим прямо (агент реализует),
git (commit/tag/push) — Дмитрий. Пакет = Pest, ревью-гейт (`/security-review` + `/code-review`) обязателен.

Контекст (из разведки, проверено по коду):
- Оператор (super-admin) заперт в маршрутах `admin.*` middleware `larafoundry.confine_admin`
  (`RedirectSuperAdminToConsole`) + whitelist `larafoundry.security.super_admin.allowed_routes`
  (`admin.*`, `pin.*`, `logout`, `password.confirm*`, `larafoundry.language.switch`, `profile.ui-settings.update`).
  Поэтому нельзя постить из профиля оператора в юзерские `/profile/*`, `/user/*`, `/settings/*`, `/auth/sessions/*` —
  туда его не пустит. Делаем тонкие admin-эндпоинты, делегирующие в те же Actions (шаблон — `SecurityController@updatePassword`).
- Super-admin = `is_admin=true` И (если сконфижен `security.super_admin.email`) совпадение email (см. `VisitorStatus::isAdmin`).

---

## Задача 1 — Хаб профиля оператора `/admin/profile` (5 вкладок)

Сейчас в пул-ауте одна ссылка «Безпека оператора» → `/admin/security` → страница `Admin/Security/Index.vue`
(только 2FA/PIN/пароль). Нужен полноценный профиль-хаб оператора с вкладками:
**Профіль · Фото · Безпека · Сеанси · Параметри** (Danger zone НЕ включаем).

### Backend
Новые маршруты в `routes/admin.php`, в группе `larafoundry.admin`, **ВНЕ** OTP-степ-ап гейта (как `security.show`),
чтобы оператор всегда попадал в свой профиль (и мог пройти enrolment 2FA):

- `GET  /admin/profile` → `Admin\ProfileController@show` — name `admin.profile.show` — рендер `Admin/Profile/Hub`.
- `PUT  /admin/profile/information` → `@updateInformation` (делегирует в `UpdateUserProfileInformation`) — `admin.profile.information.update`, throttle.
- `POST /admin/profile/avatar`  → `@updateAvatar` (делегирует в `StoreUploadedFileAction` + `MediaStorage`) — `admin.profile.avatar.update`.
- `DELETE /admin/profile/avatar` → `@destroyAvatar` — `admin.profile.avatar.destroy`.
- `PUT  /admin/profile/account` → `@updateAccount` (делегирует в `SettingsRepository`, user-scope) — `admin.profile.account.update`.
- `DELETE /admin/profile/sessions/{session}` → `@destroySession` (`SessionInvalidator::invalidateOne`, проверка `user_id`==оператор) — `admin.profile.sessions.destroy`.
- `DELETE /admin/profile/sessions` → `@destroyOtherSessions` (`SessionInvalidator::invalidateOthers`) — `admin.profile.sessions.destroy-others`.

`@show` собирает union-пропсы: из юзерского `ProfileController@index` (`profile`=ProfileResource, `sessions`,
`uiSettings`, `uiSettingsSchema`, `accountSettings`, `pin`) + из `SecurityController@show`
(`two_factor_enabled`, `two_factor_setup`, `recovery_codes`, `can_manage_two_factor`, `has_pin`, `pin_length`, `has_password`).
Stepped-up state — как в `SecurityController::show` (сессионный ключ `EnsureAdminOtpVerified::SESSION_KEY`).
`ProfileResource` работает на операторе без изменений.

Старый `/admin/security` (`admin.security.show`, GET) → редирект на `admin.profile.show` (`?tab=security`).
Экшн-маршруты `/admin/security/*` (2FA enable/confirm/disable/regen, password) **остаются** (вкладка «Безпека» постит в них).
Их redirect-таргеты (`admin.security.show`) поправить на `admin.profile.show` c `tab=security`.

### Frontend
`resources/js/Pages/Admin/Profile/Hub.vue` — обёртка `AdminLayout`, 5 вкладок (client-side `activeTab`, синк в URL `?tab=`
через `history.replaceState`, чтобы серверные редиректы вкладки «Безпека» возвращали на неё). Переиспользуем секции:
- Профіль → `Pages/Profile/sections/ProfileForm.vue` c новым пропом `endpoint` (дефолт `/user/profile-information`) = `/admin/profile/information`.
- Фото → `AvatarManager.vue` c пропом `endpoint` (дефолт `/profile/avatar`) = `/admin/profile/avatar`.
- Безпека → новый `Pages/Admin/Profile/sections/OperatorSecurity.vue` (вынести контент из `Admin/Security/Index.vue`), постит в `/admin/security/*` без изменений.
- Сеанси → `SessionsManager.vue` c пропом базового эндпоинта (дефолт `/auth/sessions`) = `/admin/profile/sessions`.
- Параметри → `Appearance.vue` (как есть, ходит в whitelisted `profile.ui-settings.update`) + `SettingsForm.vue` c `endpoint="/admin/profile/account"`.

Пропы `endpoint` — опциональные с юзерскими дефолтами: юзерский `ProfileHub` не меняет поведение.

---

## Задача 2 — Ссылка в пул-ауте оператора

`resources/js/components/admin/OperatorPullout.vue`: ссылка `href="/admin/security"` c лейблом `$t('Operator security')`
→ `href="/admin/profile"`, лейбл `$t('Profile')`, иконка — профиль/пользователь.

---

## Задача 3 — Sticky-хедер в админке

`resources/js/layouts/AdminLayout.vue`, `<header class="border-b border-border bg-surface">` →
добавить `sticky top-0 z-30` (bg уже непрозрачный; layout скроллит документ, sticky относительно вьюпорта сработает).
Только `AdminLayout` (по запросу «в админке»). `AppLayout` — не трогаем.

---

## Задача 4 — Скрывать super-admin из списков юзеров (opt-in)

Конфиг: новый ключ в блоке `admin` `config/larafoundry.php` — `'exclude_super_admin_from_lists' => true`.

Local-scope в трейте `src/Auth/Concerns/IsLaraFoundryUser.php`:
```php
public function scopeWithoutSuperAdmin($query)
{
    if (! config('larafoundry.admin.exclude_super_admin_from_lists', true)) {
        return $query;
    }
    return $query->where(function ($q) {
        $q->where('is_admin', false)->orWhereNull('is_admin');
    });
}
```
(Исключаем `is_admin=true` — прямое «админа в списках нет». Config-flag гейтит поведение.)

Применить ТОЛЬКО в list/count-точках (НЕ глобальный scope — auth/login/impersonate-by-id/edit-by-id не ломаем):
- `src/Admin/Http/Controllers/UserController.php` — `index()` и `search()`: `$this->query()->withoutSuperAdmin()`.
  НЕ трогать общий `query()`/`find()` (by-id правки/блок остаются рабочими).
- `src/Dashboard/Support/DashboardMetricsService.php` `users()` — считать по `withoutSuperAdmin()`.
- Тикет-пикер (`StoreTicketRequest` admin) — hardening `exists`-правила: исключить `is_admin=true` под тем же флагом.
- `SendBroadcastNotificationJob` уже исключает оператора по email — оставить как есть (не дублировать).

---

## Тесты (Pest), i18n, ревью

- Pest: доступ оператора к `/admin/profile` (200, пропсы), персист payload (`assertDatabaseHas` полного набора — не только редирект),
  avatar update/destroy, account update, session destroy/others; confine: оператор ПУЩЕН на `admin.profile.*`,
  но всё ещё bounced на `/profile`,`/user`; security-пропсы. Задача 4: index/search/dashboard исключают оператора,
  find-by-id всё ещё находит; флаг=false → включён.
- i18n en+uk на все новые строки (стандарт ISO 639-1). Ключ `Profile` — проверить, что переведён (uk «Профіль»).
- `/security-review` + `/code-review` по git-diff, фиксы, затем имена коммитов Дмитрию + тег ядра (semver).
- После зелёного — СТОП на визуальную проверку Дмитрия в host (bump core через composer path + `npm run build`).
