# Фаза 3a — Admin Users table → паритет с kohana_legacy (ЯДРО larafoundry)

Создан: 2026-07-10 01:12
Выполнен: 2026-07-10

Ты правишь **пакет-ядро** `dmitryisaenko/larafoundry` (namespace `Dmitryisaenko\LaraFoundry\`, `D:\Development\larafoundry`).
Это Фаза 3a большого трека «админка ядра = паритет с legacy, сделать один раз».
Мастер-план: `foundry/ai/admin-core-vs-legacy-gap.md` (Часть 3B + Часть 5, пункт 3). Читай его при необходимости.

Фаза 3 разбита на три суб-фазы (решение Дмитрия 2026-07-10):
- **3a (ЭТОТ промпт)** — таблица Users до паритета: колонки, row-действия, фильтры, PII opt-in через config.
- **3b (позже)** — формы Create/Edit + мини-фича **social links** (хранилище = отдельная таблица) + social-icons колонка + SMS-seam.
- **3c (позже)** — host-интеграция в kohana.io.

⚠️ **social-icons колонка и хранилище social links — НЕ в 3a, а в 3b.** В 3a только зарезервируй токен `'social'`
в словаре config (см. ниже), но НЕ реализуй его. Всё остальное из таблицы-паритета — здесь.

---

## Жёсткие правила (НЕ нарушать)

1. **Git — не трогаешь.** Никаких `git add/commit/tag/push`. Довести до зелёного (Pest + линт) и отдать **имя коммита**
   (английский, plain-text, одной строкой). Коммит/тег/пуш делает Дмитрий сам.
2. **Только ядро.** Host kohana.io в этой фазе НЕ трогаешь (это 3c). Никаких правок в `D:\Development\kohana.io`.
3. **Тесты обязательны** — Pest на каждый кусок бэка; если есть vitest на изменённые Vue-компоненты — прогони.
   У пакета НЕТ `npm run build` (приватный, отдаёт исходники) — сборку не запускай, гоняй только тест-раннеры.
4. **i18n — только `en` + `uk`.** 10 языков придут Фазой 6 (последней). Новые ключи добавляй в оба и всё.
5. **Диалоги подтверждения — ядровый `confirm()` / dialog-примитивы, НЕ нативный `window.confirm`/`alert`.**
6. **Без длинных тире «—» в видимом тексте UI** (лейблы/переводы) — только дефис. (В коде-плейсхолдерах существующий `—`
   не трогай массово, но НОВЫЙ видимый текст — без em-dash.)
7. **Ревью-гейт в конце:** `/security-review` + `/code-review` по git-diff (крупное → `/code-review ultra`), пофиксить
   находки, потом отдать имя коммита. Порядок: build → зелёные тесты → ревью+фиксы → имя коммита (тег вешает Дмитрий в конце фазы, не сейчас).

---

## Что уже есть (переиспользовать, НЕ переписывать)

- Колонки users уже в миграции `2026_01_01_000100_add_larafoundry_columns_to_users_table.php`:
  `middlename`, `phone`, `phone_verified_at`, `sex`, `birth_date`, `ui_settings`, `provider_name`, `email_verified_at`,
  `user_blocked_at`, `block_code`, `user_blocked_status`, `user_deleted_at`, `last_activity_at`, `country`, `locale`.
- `AdminUserResource` (`src/Admin/Http/Resources/AdminUserResource.php`) — сериализует юзера; несёт host-seam
  `extra()` → `extra_columns`. **Seam НЕ ломать.**
- `AdminUsersFilter` (`src/Admin/Http/Filters/AdminUsersFilter.php`) — reflection-based фильтр: `search`, `registered`,
  `emailVerified`, `status`, `recentActivity`, `locale`, `authType`.
- `UserController` (`src/Admin/Http/Controllers/UserController.php`) — index/edit/create/store/update/search/block/
  unblock/destroy/undelete; `block()` УЖЕ принимает `reason` + `block_code` (clamp 0-255); `audit()` пишет в лог `admin`.
- Связи на User-трейте `BelongsToTenancy`: `companies()` (все), `ownedCompanies()`, `employeeCompanies()`.
- Роуты: `admin.users.*` (routes/admin.php), `admin.activity-log.*` (phase 2.1), `admin.tickets.create` (phase 4.2).
- Vue: `resources/js/components/admin/UsersTable.vue` (+ host `extra_columns` seam) и `UsersTableActions.vue`.
- Config `larafoundry.admin.*`: `users_per_page`, `user_resource` (seam). Раздел в `config/larafoundry.php` ~стр. 381.

---

## Развилки — УЖЕ решены (не переспрашивать)

- **PII-колонки opt-in через config** (решение №2, 2026-07-09). Дефолт свежей установки = privacy-clean (для публичного
  пакета/GDPR). Включение — один config-массив. Токены opt-in: `phone`, `sex`, `age`, `social`.
  (`social` только зарезервировать — реализация в 3b.)
- **Comp./Empl. — НЕ opt-in**, показывается всегда (расширяем существующую колонку Companies).
- **SMS: только force/unverify телефона, БЕЗ отправки/resend** (решение Дмитрия 2026-07-10). Контракт SmsSender НЕ закладываем.

---

## Deliverables

### 1. Config: PII opt-in словарь

В `config/larafoundry.php`, раздел `admin`, добавь:

```php
// Which optional user-list columns the operator console shows. Default is
// empty = a privacy-clean table (name/email/country/language/auth/companies/
// registered/last-activity/status) suitable for a public package and GDPR.
// A host opts extra columns in, once, in its published config. Recognised
// tokens: 'phone', 'sex', 'age', 'social'. Unknown tokens are ignored.
// ('social' lights up in phase 3b once social-link storage ships.)
'user_columns' => [],
```

- Backend читает этот массив и решает, какие поля СЕРИАЛИЗОВАТЬ (не эмить `sex`/`age`/`phone` в JSON, если не включены —
  privacy-clean на уровне payload, не только на уровне отрисовки).
- Массив включённых токенов прокинь в Inertia-проп страницы (напр. `userColumns`), чтобы Vue-таблица знала, какие
  заголовки/ячейки рендерить и какие фильтры показывать.
- Санитизируй: пересекай config со списком известных токенов (крафт в config не должен протащить произвольный ключ).

### 2. AdminUserResource — новые поля (условные)

- **Всегда:** `id` (уже есть). `owned_companies_count` + `employee_companies_count` для колонки «Comp./Empl.»
  (owned = `ownedCompanies`, employee = `employeeCompanies`; используй `whenCounted`). Оставь `companies_count`
  если на него кто-то опирается, ИЛИ переведи фронт на два новых — на твоё усмотрение, но не сломай существующие тесты.
- **Только если токен включён:**
  - `phone` (уже эмитится безусловно — теперь ГЕЙТЬ за токеном `phone`; убедись, что снятие не ломает существующие тесты —
    поправь их под gated-поведение),
  - `sex` (строка как в колонке; фронт покажет иконку/лейбл Male/Female),
  - `age` — целое, ВЫЧИСЛЯЕМОЕ из `birth_date` (`birth_date?->age`), null-safe; при выключенном токене не эмить.
- `social` — НЕ трогать (3b).
- Host-seam `extra()`/`extra_columns` — **сохранить как есть.**

### 3. AdminUsersFilter — новые методы

Добавь (reflection-filter: каждый метод = request-ключ; пустое значение база пропускает):
- `country(string $value)` — точное совпадение по `country`.
- `phoneVerified(string $value)` — `'verified'` → `whereNotNull('phone_verified_at')`, иначе `whereNull`.
- `sex(string $value)` — точное совпадение по `sex` (валидные значения — как хранятся; неизвестное = no-op-безопасно).
- `ageRange(string $value)` — возрастные корзины по `birth_date`. Определи разумные корзины
  (напр. `18-25`, `26-35`, `36-45`, `46-60`, `60+`) и переведи в окно по `birth_date`
  (`whereBetween('birth_date', [now-maxLet, now-minLet])`). Неизвестная корзина = no-op (как `status`/`registered`).
- Все новые enum-фильтры: неизвестное значение = **no-op**, не «схлопывание в одну ветку» (паттерн как у `authType`).
- `sex`/`ageRange` фильтры на бэке существуют всегда (безвредны на непополненной колонке); ПОКАЗ фильтра на фронте —
  только когда включён соответствующий токен.

### 4. UserController — новые действия + проброс config

- **index:** `withCount(['ownedCompanies', 'employeeCompanies'])` (+ то, что нужно колонкам); прокинь `userColumns`
  (санитизированный массив) в проп; расширь список `filters` новыми ключами (`country`, `phoneVerified`, `sex`, `ageRange`).
- **verifyEmail / unverifyEmail / verifyPhone / unverifyPhone** — force-set/clear `email_verified_at` / `phone_verified_at`
  (`forceFill(['...' => now()/null])`). Каждое пишет в `audit()` (описания `admin.user.email_verified` /
  `admin.user.email_unverified` / `admin.user.phone_verified` / `admin.user.phone_unverified`). Никакой отправки SMS/письма.
- **block-with-reason** — бэк уже принимает `reason`. Убедись, что action-поток проводит `reason` (+ опц. `block_code`)
  из модалки. Персист в `user_blocked_status` уже есть.
- Роуты в `routes/admin.php`, группа `users`:
  ```php
  Route::post('{user}/verify-email',   [UserController::class, 'verifyEmail'])->name('verify-email');
  Route::post('{user}/unverify-email', [UserController::class, 'unverifyEmail'])->name('unverify-email');
  Route::post('{user}/verify-phone',   [UserController::class, 'verifyPhone'])->name('verify-phone');
  Route::post('{user}/unverify-phone', [UserController::class, 'unverifyPhone'])->name('unverify-phone');
  ```

### 5. Per-user Logs + Create-ticket (row-действия, лёгкая интеграция)

- **Per-user Logs:** row-действие «Logs» → ссылка на `admin.activity-log.index` с фильтром по этому юзеру (subject).
  Проверь, поддерживает ли activity-log index фильтр по subject/user; если нет — добавь минимальный фильтр-параметр
  (напр. `?subject_id=` / `?user=`) в его контроллер/фильтр. Не расширяй сверх необходимого.
- **Create-ticket-for-user:** row-действие «Create ticket» → ссылка на `admin.tickets.create` с предзаполненным юзером
  (напр. `?user={id}`). Проверь admin ticket create-форму: принимает ли предвыбранного юзера; если нет — дай ей опц.
  проп предвыбора (минимально). Не переписывай тикеты.

### 6. Vue — таблица, действия, модалка блокировки

- **UsersTable.vue:** добавь колонки, управляемые пропом `userColumns`:
  - `ID` (всегда),
  - `Phone` (+ галочка verified, если `phone_verified`) — только при токене `phone`,
  - `Sex` (иконка/лейбл Male/Female) — только при токене `sex`,
  - `Age` — только при токене `age`,
  - «Comp./Empl.» — расширь существующую колонку Companies до `owned / employee` (всегда).
  Host-seam `extra_columns` и его union-header логику **не ломать**; пересчитай `CORE_COLUMNS`/`colspan` динамически
  (учитывая, какие опц. колонки включены), чтобы empty-state `colspan` был верным.
- **UsersTableActions.vue:** добавь действия: Verify/Unverify email (по `email_verified`), Verify/Unverify phone
  (по `phone_verified`, только если токен `phone` включён), Logs, Create ticket. Блокировка — через модалку с причиной.
- **Модалка блокировки с причиной:** новый небольшой компонент (напр. `BlockUserDialog.vue`) на ядровых dialog-примитивах
  (НЕ нативный prompt/confirm): textarea «Block reason» (+ опц. код), submit → POST `admin.users.block` с `reason`.
  Экспортируй что нужно из barrel, если host будет переиспользовать (host-интеграция — 3c, но компонент кладём в ядро сейчас).
- Все destructive-действия (unverify, block, delete) подтверждать ядровым `confirm()` / модалкой.

### 7. i18n (en + uk)

Добавь новые frontend-ключи в `resources/js` lang JSON (или где ядро держит frontend-строки) в `en` и `uk`:
`ID`, `Sex`, `Age`, `Phone`, `Male`, `Female`, `Verify email`, `Unverify email`, `Verify phone`, `Unverify phone`,
`Logs`, `Create ticket`, `Block reason`, `Age range`, названия возрастных корзин, лейблы новых фильтров
(`Country`, `Phone verified`), `Comp. / Empl.` (без em-dash). Backend flash/audit строки (`larafoundry::admin.users.*`)
для verified/unverified — тоже en+uk.

### 8. Тесты (Pest)

Покрой:
- Resource: `sex`/`age`/`phone` НЕ появляются в JSON при пустом `user_columns`; ПОЯВЛЯЮТСЯ при включённых токенах;
  `age` корректно вычислен из `birth_date` (и null при отсутствии).
- Counts: `owned_companies_count` / `employee_companies_count` считаются верно (юзер-владелец vs юзер-сотрудник).
- Фильтры: `country`, `phoneVerified`, `sex`, `ageRange` (по корзине), неизвестное значение = no-op (не прячет всех).
- Действия: verify/unverify email+phone меняют колонку И пишут запись в activity-log (`admin`) с нужным описанием.
- Block-with-reason: `user_blocked_status` персистит переданную причину (assertDatabaseHas на полный payload, не только редирект).
- Per-user Logs фильтр (если добавил параметр) — отдаёт только события этого subject.
- Гейтинг фильтров/колонок не ломает существующие AdminUsersTest.

Следуй правилу: тесты записи ассертят **персист полного payload** (`assertDatabaseHas`), а не только редирект.

---

## Definition of Done (3a)

- [ ] Config `user_columns` + санитизация + проброс в проп.
- [ ] Resource: id, owned/employee counts (всегда), phone/sex/age (gated), social НЕ трогаем.
- [ ] Filter: country/phoneVerified/sex/ageRange, все no-op на неизвестном.
- [ ] Controller: counts, проброс config/filters, verify/unverify email+phone (audited), block-with-reason проведён.
- [ ] Роуты verify/unverify.
- [ ] Per-user Logs + Create-ticket линки (+ минимальные фильтр/предвыбор, если не было).
- [ ] Vue: колонки по `userColumns`, действия, BlockUserDialog (ядровый confirm), seam не сломан.
- [ ] i18n en+uk.
- [ ] Pest зелёный + vitest (если затронуты Vue-тесты).
- [ ] `/security-review` + `/code-review`, находки пофикшены.
- [ ] Имя коммита отдано Дмитрию (тег он повесит в конце фазы — НЕ сейчас).

Дату выполнения впиши в шапку. Отчитайся: что сделано, сколько тестов, находки ревью и как закрыты, имя коммита.
