# Prompt — Admin operator security page + right operator pull-out

Создан: 2026-07-16 21:13
Выполнен: <впиши дату выполнения агентом>

Пакет-ядро `Dmitryisaenko\LaraFoundry\` (`d:\Development\larafoundry`). Всё делается ТОЛЬКО в ядре.
Язык кода/комментариев/тестов — английский. Git (commit/tag/push) делает Дмитрий — ты доводишь до
зелёного (Pest **и** vitest) и отдаёшь имя коммита. НЕ вызывай git.

---

## Зачем это (контекст)

Супер-админ (оператор консоли `/admin`) сейчас:
1. В правой части шапки `AdminLayout.vue` имеет ТОЛЬКО `NotificationBell` — нет пул-аута оператора
   (тема / язык / безопасность / выход). У обычного юзера аналог есть в host, у оператора в ядре — нет.
2. Не может включить 2FA/PIN из запертой консоли — **chicken-and-egg**: OTP-гейт
   `EnsureAdminOtpVerified` при `require_otp=true` редиректит оператора без 2FA на
   `two_factor_setup_route`, но по дефолту это null → 403 (fail-closed), а конфайн
   `RedirectSuperAdminToConsole` держит оператора в `allowed_routes` (`admin.*`, `pin.*`, `logout`,
   `password.confirm*`) — обычные `profile.*` / Fortify `/user/*` экраны 2FA ему недоступны.

Задача: дать оператору (а) правый пул-аут в шапке админки и (б) внутри-консольную страницу
безопасности `admin.security`, с которой он включает 2FA (TOTP) + PIN + меняет пароль, и закрыть
петлю включения 2FA.

Разведка кодовой базы уже проведена — точные факты ниже. Перед правкой перечитай указанные файлы.

---

## A. Правый пул-аут оператора (`OperatorPullout`)

**Где цепляется:** `resources/js/layouts/AdminLayout.vue`, правый `<nav>` (строки 49–52), сейчас там
`<NotificationBell />` + `<slot name="nav" />`. Добавь ПОСЛЕ `NotificationBell` кнопку-триггер:
аватар оператора (`components/media/UserAvatar.vue`, src=`page.props.auth.user.avatar_url`,
name=`...user.name`, size ~32) + (опц.) имя. Клик открывает пул-аут.

**Компонент:** новый `resources/js/components/admin/OperatorPullout.vue`. **Переиспользуй инфру
`AdminFilterDrawer.vue`** (правый slide-over, `useScrollLock`, focus-trap, Esc, overlay,
`role=dialog aria-modal` — всё уже отревьюено в v0.34.0) как оболочку: оберни в
`<AdminFilterDrawer v-model:open title="Operator">`, содержимое в default-слот, «Log out» в
`footer`-слот. Если для шапки с аватаром/именем/email оболочки не хватает — допустимо добавить в
`AdminFilterDrawer` необязательный `#header`-слот (не ломая текущее использование в Users), либо
собрать `OperatorPullout` на `useScrollLock` + тот же a11y-паттерн. Не дублируй логику scroll-lock.

**Содержимое пул-аута (утверждено):**
1. **Шапка:** аватар + имя + email оператора (как в host `UserPullout.vue` header), кнопка закрытия.
2. **Тема (save-only, БЕЗ мгновенного применения).** Кнопка-переключатель светлая/тёмная. По клику —
   `router.put('/profile/ui-settings', { key: 'theme', value }, { preserveScroll: true })`. Тему
   применяет host на следующей загрузке; сейчас мгновенного переключения НЕ делаем (полноценный
   DarkMode — отдельная host-задача). Текущее значение темы бери из shared-props оператора: проверь,
   шарится ли `ui_settings.theme` (напр. `page.props.auth.user.ui_settings.theme` или отдельный
   shared-проп); если глобально не шарится в admin-контексте — добавь минимальный shared-проп
   `operator_ui_settings` (или подключи существующий механизм) через тот же
   `HandleInertiaRequests`-путь, что и в app. Значения темы — из `config('larafoundry.profile.ui_settings.theme')`
   (`['light','dark','system']`); переключай между light/dark (иконки солнце/луна как в host UserPullout).
   Если текущее значение прочитать нельзя — деградируй gracefully (кнопка «Toggle theme», просто PUT).
3. **Язык.** Переиспользуй `components/LocaleSwitcher.vue` как есть (он POST-ит
   `route('larafoundry.language.switch')`, рендерит ничего при ≤1 локали). Либо inline-список флагов
   как в host `UserPullout.vue` (строки 117–137) — на твоё усмотрение, но предпочтителен готовый
   `LocaleSwitcher`.
4. **«Operator security»** — `<Link href="/admin/security">` (route `admin.security.show`), закрывает пул-аут.
5. **Log out** (footer) — `<Link href="/logout" method="post" as="button">` (route `logout` уже в
   allow-list). Иконка выхода — см. `components/navigation/NavIcon.vue:30`.

Тема-токены (`text-ink`, `bg-surface`, `border-border` …), не сырой Tailwind-палитр без `dark:`.
Все интерактивные элементы — с `aria-label`/`title`. Никаких длинных тире «—» в текстах.

---

## B. Страница безопасности оператора (`admin.security`)

**Роут.** В `routes/admin.php`, в группе `larafoundry.admin` (`->prefix('admin')->name('admin.')`),
но **ВНЕ** вложенной `Route::middleware('larafoundry.admin.otp')->group(...)` — там же, где
`otp.show`/`otp.verify` (строки 42–45). Причина: оператор без 2FA редиректится сюда OTP-гейтом; если
страница будет за гейтом — петля. Так же, как challenge-роут, эта страница за `larafoundry.admin`
(супер-админ-гейт) + `web/auth/verified`, но НЕ за OTP-гейтом.

```php
// Operator self-service security (2FA enrolment / PIN / password). Deliberately
// OUTSIDE the larafoundry.admin.otp gate below — the gate redirects an
// un-enrolled operator here, so gating it would loop (same reason as otp.show).
Route::get('security', [SecurityController::class, 'show'])->name('security.show');
Route::post('security/two-factor/enable',  [SecurityController::class, 'enableTwoFactor'])->name('security.two-factor.enable');
Route::post('security/two-factor/confirm', [SecurityController::class, 'confirmTwoFactor'])->middleware('throttle:6,1')->name('security.two-factor.confirm');
Route::get('security/two-factor/qr-code',        [SecurityController::class, 'qrCode'])->name('security.two-factor.qr-code');
Route::get('security/two-factor/recovery-codes', [SecurityController::class, 'recoveryCodes'])->name('security.two-factor.recovery-codes');
Route::post('security/two-factor/recovery-codes',[SecurityController::class, 'regenerateRecoveryCodes'])->name('security.two-factor.recovery-codes.regenerate');
Route::delete('security/two-factor', [SecurityController::class, 'disableTwoFactor'])->name('security.two-factor.disable');
Route::put('security/password', [SecurityController::class, 'updatePassword'])->name('security.password.update');
```

Всё под `admin.security.*` → автоматически в `allowed_routes` конфайна (`admin.*`), правки конфайна
для этих роутов НЕ нужны.

**Контроллер** `src/Admin/Http/Controllers/SecurityController.php` (namespace как у остальных Admin-контроллеров).
Действия — **тонкие прокси поверх Fortify-actions/контрактов**, чтобы не зависеть от неименованных
Fortify `/user/*`-роутов и не открывать их конфайну. Смотри как это делает Fortify:
- `enableTwoFactor` → инвок `Laravel\Fortify\Actions\EnableTwoFactorAuthentication` на `$request->user()`.
- `confirmTwoFactor` → `Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication` (валидирует `code`;
  в конфиге `profile.two_factor.confirm = true` — подтверждение обязательно).
- `qrCode` → вернуть `['svg' => $user->twoFactorQrCodeSvg()]` (как отдаёт Fortify `two-factor-qr-code`).
- `recoveryCodes` (GET) → `json_decode(decrypt($user->two_factor_recovery_codes), true)`.
- `regenerateRecoveryCodes` → `Laravel\Fortify\Actions\GenerateNewRecoveryCodes`.
- `disableTwoFactor` → `Laravel\Fortify\Actions\DisableTwoFactorAuthentication`.
- `updatePassword` → ядровый `UpdateUserPassword`-action (тот, что стоит за Fortify `PUT /user/password`;
  найди его — используется в `Pages/Profile/sections/PasswordForm.vue`). Валидация:
  `current_password` + `password` (`Password::defaults()`) + `password_confirmation`.

Свериться с эталоном UI/потока: `resources/js/Pages/Auth/TwoFactorSettings.vue` (какие данные/шаги
энролмента: enable → показать QR+recovery → confirm кодом → disable), `Pages/Profile/PinManager.vue`
(PIN), `Pages/Profile/sections/PasswordForm.vue` (пароль). GET-чтения (qr/recovery) во фронте —
через `fetch()` (JSON), мутации — через Inertia `router`, как в `TwoFactorSettings.vue`.

**Vue-страница** `resources/js/Pages/Admin/Security/Index.vue` (под `AdminLayout`, `title="Security"`).
Три секции-карточки:
1. **Two-factor authentication (TOTP)** — статус (вкл/выкл), кнопка Enable → показать QR (svg) +
   recovery-codes → поле кода → Confirm; когда включено — Disable + Regenerate recovery codes. Эндпоинты
   — `admin.security.two-factor.*` (НЕ Fortify `/user/*`).
2. **PIN** — переиспользуй существующий `Pages/Profile/PinManager.vue` (постит на `/pin/enable`,
   `/pin/disable`, `/pin/lock` — `pin.*` уже allow-listed). Импортни компонент, не дублируй.
3. **Password** — форма current/new/confirm → `router.put(route('admin.security.password.update'))`.
   Можно переиспользовать/адаптировать `Pages/Profile/sections/PasswordForm.vue`, но он постит на
   `/user/password` — для оператора нужен `admin.security.password.update` (проп-эндпоинт или новый
   admin-вариант). Не ломай исходный PasswordForm (его юзает app-профиль).

Страница `show()` в контроллере передаёт пропы: `two_factor_enabled` (bool,
`$user->hasEnabledTwoFactorAuthentication()`), `two_factor_confirmed` (bool), `has_pin`
(`$user->hasPin()`), `pin` config (length и т.п. как ждёт `PinManager`), `has_password`
(`$user->password !== null`).

---

## C. Закрыть петлю включения 2FA

`config/larafoundry.php`, блок `security.super_admin` (строки ~179–201):
- **`two_factor_setup_route`**: дефолт сейчас `env('LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE')` (=null → 403).
  Поменяй дефолт на внутри-консольную страницу:
  `env('LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE', 'admin.security.show')`. Теперь оператор без 2FA
  редиректится на `admin.security.show` (она вне OTP-гейта → доступна), включает 2FA, дальше каждая
  сессия = свежий OTP-step-up. Комментарий в конфиге обнови.

`RedirectSuperAdminToConsole` `allowed_routes` (тот же блок) — **добавь две строки** для пул-аута
оператора (язык + сохранение темы), с комментарием-обоснованием:
```php
'allowed_routes' => [
    'admin.*',
    'pin.*',
    'logout',
    'password.confirm*',
    // Operator pull-out preference actions: language switch and the allowlisted
    // ui-settings PUT (theme). Both are per-user, self-scoped preference endpoints
    // the operator needs from the console shell — not tenant surface.
    'larafoundry.language.switch',
    'profile.ui-settings.update',
],
```
(Проверь точные имена роутов: `web.php:21` = `larafoundry.language.switch`; `profile.php:33` =
`ui-settings.update` в группе `profile.` → полное имя вероятно `profile.ui-settings.update` —
подтверди по `routes/profile.php`.)

---

## D. Экспорт / регистрация

- `resources/js/index.js` — экспортни новый `OperatorPullout` из барреля (как `AdminFilterDrawer` в v0.34.0),
  если он должен быть доступен host'у. (Страница `Admin/Security/Index.vue` резолвится Inertia-резолвером
  host'а из опубликованных страниц — проверь, как публикуются admin-страницы ядра: `larafoundry-pages`.)
- Локали: добавь недостающие ключи в `lang/frontend/en.json` + `uk.json` (напр. «Operator», «Security»,
  «Two-factor authentication», «Enable», «Disable», «Confirm», «Recovery codes», «Regenerate»,
  «Current password», «New password», «Log out», «Toggle theme» и т.п.). uk — корректный перевод.
- Строки бэка (flash/validation) — через `larafoundry::` переводы, если добавляешь новые.

---

## E. Тесты (ОБА раннера — обязательно)

**Pest** (`vendor/bin/pest`), новый файл(ы) в `tests/Feature/Admin/`:
- `admin.security.show` доступна оператору БЕЗ включённой 2FA (не редиректит в петлю) — ключевой тест
  закрытия chicken-and-egg. Проверь при `require_otp=true` + оператор без `two_factor_secret`: GET
  `admin/security` = 200 (а не редирект на otp/setup).
- Обычный (не-admin) юзер на `admin.security.*` = 403/redirect (гейт `larafoundry.admin`).
- `enableTwoFactor` → у пользователя появляется `two_factor_secret`; `confirmTwoFactor` с валидным кодом
  подтверждает; `disableTwoFactor` очищает. (Можно опереться на существующие Fortify-тест-хелперы/паттерны
  ядра — посмотри как тестируется 2FA сейчас.)
- `updatePassword`: неверный `current_password` → ошибка; верный → пароль сменился (assertDatabaseHas по
  наличию изменения хэша — учти, что пароль hashed, ассерть факт смены через `Hash::check`, не равенство).
- Роуты `admin.security.*` попадают в allow-list конфайна (оператор их проходит, не редиректится в консоль).
- `two_factor_setup_route` дефолт = `admin.security.show`: оператор без 2FA, зайдя на любой
  OTP-гейченный admin-роут, редиректится на `admin/security` (а не 403).

**vitest** (`npm run test:js`, `tests-js/`) — у ядра ОТДЕЛЬНЫЙ CI-джоб `Frontend (vitest)`, его пропуск
роняет пуш (урок v0.34.0). Покрой:
- `OperatorPullout.vue`: рендерит имя/email оператора; клик по «Log out» есть; переключатель темы PUT-ит
  на `/profile/ui-settings` с `{key:'theme', ...}` (мокни `router`); язык-строка присутствует.
- `Admin/Security/Index.vue`: рендерит три секции; кнопки 2FA/пароль имеют доступные лейблы; мок
  `router`/`fetch`.
- Не сломай существующий `tests-js/components/AdminConsole.test.js` и прочие.

Прогони ОБА (`vendor/bin/pest` и `npm run test:js`) до зелёного ПЕРЕД отдачей коммита.

---

## F. Ревью-гейт (после того как оба раннера зелёные)

Порядок: сделать всё зелёным → Дмитрий делает первый commit (без тега) → ревью-гейт (2 агента по
git-diff: security + correctness) → фиксы → commit фикса → tag (следующий semver после v0.34.0) → push.
Не тегай сам. Прогон ревью — по реальному diff.

---

## G. Что НЕ делать (границы)

- НЕ реализовывать полноценное применение тёмной темы (тоггл только сохраняет; DarkMode — отдельная
  host-задача).
- НЕ трогать app-профильные `Pages/Profile/*` компоненты деструктивно (PinManager/PasswordForm
  переиспользуем/адаптируем аддитивно, они нужны app-профилю).
- НЕ расширять `allowed_routes` шире, чем язык + ui-settings (не открывать оператору `profile.*` целиком).
- НЕ трогать host (`kohana.io`) — интеграция отдельным шагом после тега (host уберёт свой
  `LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE`-override, добавит пул-аут в host-скин если нужно, проверит
  применение темы). Только зафиксируй host-хвосты в конце ответа.

---

## Ключевые файлы (перечитать перед правкой)

- Шапка админки: `resources/js/layouts/AdminLayout.vue` (правый nav 49–52)
- Инфра slide-over: `resources/js/components/admin/AdminFilterDrawer.vue`, `resources/js/composables/useScrollLock.js`
- Референс пул-аута (host, НЕ править): `d:\Development\kohana.io\resources\js\Components\shell\UserPullout.vue`
- Переиспользуемые: `components/LocaleSwitcher.vue`, `components/media/UserAvatar.vue`, `components/navigation/NavIcon.vue`
- 2FA эталон: `resources/js/Pages/Auth/TwoFactorSettings.vue`; PIN: `Pages/Profile/PinManager.vue`;
  пароль: `Pages/Profile/sections/PasswordForm.vue`
- Гейты: `src/Http/Middleware/EnsureAdminOtpVerified.php`, `src/Http/Middleware/RedirectSuperAdminToConsole.php`
- Admin OTP challenge (паттерн вне-гейтового роута): `src/Admin/Http/Controllers/AdminOtpChallengeController.php`
- Роуты: `routes/admin.php` (группа + otp вне вложенной), `routes/pin.php`, `routes/profile.php`, `routes/web.php`
- Конфиг: `config/larafoundry.php` (`security.super_admin` ~179–201, `profile.ui_settings.theme`, `pin`)
- ui-settings контроллер темы: `UiSettingsController` (роут `routes/profile.php:33`)
- Экспорт-барель: `resources/js/index.js`
