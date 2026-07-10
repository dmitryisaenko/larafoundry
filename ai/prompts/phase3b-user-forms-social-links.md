# Фаза 3b — Admin User Create/Edit формы + Social links + verify-manage (ЯДРО larafoundry)

Создан: 2026-07-10 02:29
Выполнен: 2026-07-10

Ты правишь **пакет-ядро** `dmitryisaenko/larafoundry` (namespace `Dmitryisaenko\LaraFoundry\`, `D:\Development\larafoundry`).
Это Фаза 3b трека «админка ядра = паритет с legacy». Мастер-план: `foundry/ai/admin-core-vs-legacy-gap.md`
(Часть 3.B + Часть 5, пункт 3). 3a (таблица Users) уже закоммичена — теперь ФОРМЫ + social links.

Фаза 3 = 3a (таблица, ✅ закоммичена) / **3b (ЭТОТ промпт)** / 3c (host-интеграция, позже).

---

## Жёсткие правила (НЕ нарушать)

1. **Git — не трогаешь.** Никаких `git add/commit/tag/push`. Довести до зелёных тестов, вернуть **имя коммита** (английский,
   plain-text, одна строка). Коммит/тег/пуш делает Дмитрий.
2. **Только ядро.** Host kohana.io НЕ трогаешь (это 3c).
3. **Тесты обязательны** — Pest на бэк, vitest на изменённые Vue. `npm run build` у пакета НЕТ — не запускай.
4. **i18n — только `en` + `uk`.** 10 языков — Фаза 6.
5. **Диалоги — ядровый `confirm()` / dialog-примитивы, НЕ нативный `window.confirm`/`alert`.**
6. **Без длинных тире «—» в НОВОМ видимом тексте UI** — только дефис.
7. **Ревью-гейт в конце запускаю Я (оркестратор), не ты.** Твоя задача: реализовать Deliverables + зелёные тесты + имя коммита.

---

## Что уже есть (переиспользовать, НЕ переписывать)

- Колонки users в миграции `2026_01_01_000100_add_larafoundry_columns_to_users_table.php`: `middlename`, `phone`,
  `phone_verified_at`, `sex`, `birth_date`, `email_verified_at` и др.
- `laraFoundryFillable()` (`src/Auth/Concerns/IsLaraFoundryUser.php`) УЖЕ включает `middlename`, `sex`, `birth_date`,
  `phone` — безопасны для `fill()`. Привилегии (`is_admin`, `*_verified_at`, `user_blocked_at`) НЕ в fillable.
- **Self-профиль — эталон валидации/хранения** (переиспользовать):
  `src/Auth/Actions/UpdateUserProfileInformation.php` правила: `middlename` nullable string max:255; `sex` nullable
  **string max:1**; `birth_date` nullable date. `ProfileResource` отдаёт `sex` сырым, `birth_date` как `Y-m-d`.
- **3a (закоммичено):** `AdminUserResource` (gated `phone`/`sex`/`age` через `when()`, `enabledColumns()` санитизация
  `array_intersect(COLUMN_TOKENS, config)`, `full()` — все PII-колонки для edit-контекста, токены `COLUMN_TOKENS =
  ['phone','sex','age','social']`, `social` ЗАРЕЗЕРВИРОВАН — реализуешь ЕГО здесь); `AdminUsersFilter`
  (country/phoneVerified/sex/ageRange); `UserController` (store/update/verifyEmail/unverifyEmail/verifyPhone/
  unverifyPhone — force, audited, без mail/SMS); роуты `admin.users.verify-email|unverify-email|verify-phone|
  unverify-phone`; Vue `UsersTable.vue` (`userColumns` проп, `OPTIONAL_TOKENS=['phone','sex','age']`),
  `UsersTableActions.vue`, `Index.vue`, `BlockUserDialog.vue`.
- Формы: `StoreUserRequest` / `UpdateUserRequest` (наследует Store), `Create.vue`, `Edit.vue`.
- `config/larafoundry.php` раздел `admin` (`user_columns`, `user_resource`, `users_per_page`).

---

## Развилки 3b — УЖЕ решены (не переспрашивать)

- **Social links хранилище = ОТДЕЛЬНАЯ ТАБЛИЦА** `larafoundry_user_social_links` (не JSON) — решение Дмитрия 2026-07-10.
- **SMS: только force/unverify телефона, БЕЗ send/resend** — эндпоинты уже есть (3a). В форме — только кнопки
  force-verify/cancel. Контракт отправки НЕ закладываем.
- **PII opt-in распространяется и на поля формы** (таблица+форма согласованы): sex-поле формы под токеном `sex`,
  birth_date под токеном `age`, social-виджет под токеном `social`. `middlename` и `phone` — в форме всегда (не gated).

---

## Deliverables

### 1. Social links — хранилище (opt-in)

- **Миграция** `database/migrations/<ts>_create_larafoundry_user_social_links_table.php` (additive, idempotent-guard
  как остальные миграции ядра): `id`, `user_id` (index + FK на users, `onDelete('cascade')`), `platform` (string 50),
  `url` (string 500), `sort` (unsignedInteger, default 0), `timestamps`. `down()` дропает таблицу.
- **Модель** `src/Profile/Models/UserSocialLink.php`: `$table = 'larafoundry_user_social_links'`, fillable
  `['user_id','platform','url','sort']`, `belongsTo` user (`config('auth.providers.users.model')`).
- **Связь** на User-трейте: добавь `socialLinks(): HasMany` в `IsLaraFoundryUser` (профиль/identity-домен),
  сортировка по `sort`. Это НЕ ломает существующее (просто новый метод).
- **Whitelist платформ** — единый источник (напр. const/статик-массив в `UserSocialLink` или config
  `larafoundry.profile.social_platforms`): `website, twitter, linkedin, github, facebook, instagram, telegram,
  youtube`. Валидация и фронт-иконки читают ОДИН источник.

### 2. Формы — валидация (StoreUserRequest / UpdateUserRequest)

- `StoreUserRequest::rules()` дополни:
  - `middlename` => `['nullable','string','max:255']`
  - `sex` => `['nullable','string','max:1']` (канон — см. под-задачу 8 про значения)
  - `birth_date` => `['nullable','date']`
  - `password` => `['required','string','min:8','confirmed']` (было без `confirmed` — добавь Confirm Password)
  - `social_links` => `['sometimes','array']`
  - `social_links.*.platform` => `['required_with:social_links','string', Rule::in(<whitelist>)]`
  - `social_links.*.url` => `['required_with:social_links.*.platform','string','max:500','url']`
    ⚠️ **Схема только http/https** — добавь правило/замыкание, отвергающее `javascript:`/`data:` и прочие схемы
    (Laravel `url` пропускает и другие схемы). Это защита от stored-XSS через `href`.
- `UpdateUserRequest::rules()`: `password` => `['nullable','string','min:8','confirmed']` (наследует остальное; email
  ignore self уже есть). Confirm срабатывает только если пароль задан.
- Валидация gated-полей присутствует ВСЕГДА (приём безвреден); показ полей на фронте — по токену.

### 3. UserController — store / update

- `store`/`update`: расширь `fill`/`$request->only` на `middlename`, `sex`, `birth_date` (все в `laraFoundryFillable`).
- **Sync social links** — вынеси в приватный метод, вызывай после `save()` в обоих:
  - Синхронизируй **только если ключ `social_links` присутствует в запросе** (`$request->has('social_links')`).
    Если поле gated/не прислано — существующие ссылки НЕ трогать (иначе выключенный токен затрёт данные — это класс
    того же бага, что HIGH в 3a).
  - Стратегия replace: удали ссылки юзера, создай из payload с `sort` по порядку. (Либо аккуратный diff — на твоё
    усмотрение, но атомарно и без утечки чужих user_id.)
  - На create — после `$user->save()` (нужен id).
- verify/unverify email+phone эндпоинты уже есть (3a) — форма их ПЕРЕИСПОЛЬЗУЕТ, новых не добавляй.

### 4. Vue — формы Create.vue / Edit.vue

- Оба: добавь поля
  - `middlename` (InputField, всегда),
  - `sex` (select, показывать при токене `sex`; опции согласованы с каноном — под-задача 8),
  - `birth_date` (InputField type="date", показывать при токене `age`),
  - `password_confirmation` (InputField type="password"; лейбл «Confirm password»),
  - **social-links виджет** (при токене `social`).
- Форма получает `userColumns` проп (прокинь из контроллера в Create/Edit так же, как index прокидывает; для edit —
  resource уже несёт поля через `full()`). Показ gated-полей — по `userColumns`.
- **Edit.vue — verify-manage блок:**
  - Email: статус (verified/не) + кнопка «Verify email» (POST verify-email) либо «Cancel verification»
    (POST unverify-email, через ядровый `confirm`).
  - Phone: то же через verify-phone/unverify-phone. Без отправки SMS — только force/cancel. (Показ phone-verify —
    логично при наличии phone; не привязывай жёстко к токену, телефон в форме есть всегда.)
  - Кнопки дёргают существующие эндпоинты 3a (`preserveScroll`).
- **Social-links виджет** — вынеси в переиспользуемый компонент `resources/js/components/admin/SocialLinksField.vue`
  (v-model массив `[{platform, url}]`): строка = select платформы (из whitelist) + url-input + кнопка удалить; кнопка
  «Add link». Значение хранится в `form.social_links`. Никакого `v-html`.
- Экспортируй новые компоненты из barrel (`resources/js/index.js`), если host будет переиспользовать в 3c.

### 5. Resource + таблица — social-icons колонка (токен `social`)

- `AdminUserResource`: добавь `social_links` — gated `when(in_array('social', $columns))`, отдаёт
  `list<{platform, url}>` из связи (`$this->whenLoaded('socialLinks', ...)` или прямое чтение). Включи `social` и в
  `full()`-набор (edit-форме нужны ссылки). ⚠️ Убедись, что связь загружена в `index()`
  (`with('socialLinks')` только когда токен включён — иначе лишний запрос).
- `UsersTable.vue`: добавь `'social'` в `OPTIONAL_TOKENS` (для colspan) и колонку social-icons при `has('social')` —
  иконки-ссылки: `<a :href="link.url" target="_blank" rel="noopener noreferrer">` + per-platform inline SVG.
  Вынеси маппинг platform→SVG в компонент `resources/js/components/admin/SocialIcon.vue`.
- `UserController::index()` — грузи `socialLinks` только при включённом токене `social`.

### 6. i18n (en + uk)

Новые ключи (оба языка): `Middle name`, `Birth date`, `Confirm password`, `Social links`, `Add link`, `Remove`,
`Verify email`/`Cancel email verification`, `Verify phone`/`Cancel phone verification`, `Email verified`/`Not verified`,
названия платформ (Website/Twitter/LinkedIn/GitHub/Facebook/Instagram/Telegram/YouTube), опции sex-select. Backend
flash-строки — если добавляешь новые.

### 7. Тесты

**Pest:**
- Social link: связь `socialLinks()`; sync на create (юзер + ссылки персистятся, `assertDatabaseHas`); sync на update
  (replace); **gated-защита** — update БЕЗ `social_links` в запросе НЕ удаляет существующие ссылки; чужие ссылки не
  затрагиваются.
- Валидация: `password` требует `confirmed` (mismatch → ошибка); `social_links.*.url` отвергает `javascript:`-схему;
  `platform` вне whitelist → ошибка; `sex` max:1; `birth_date` не-дата → ошибка.
- Persist: `middlename`/`sex`/`birth_date` сохраняются (полный payload через `assertDatabaseHas`).
- Resource: `social_links` в JSON только при токене `social`; `full()` (edit) несёт social_links независимо от config.
- (verify/unverify уже покрыты 3a — не дублируй.)

**vitest:** `SocialLinksField` (add/remove строки, v-model), gated-поля формы по `userColumns`, social-icons колонка
рендерит ссылки с `rel="noopener noreferrer"`.

### 8. Под-задача: согласовать значения `sex` (важно — иначе 3a-фильтр не находит профили)

Self-профиль хранит `sex` как **1 символ** (`max:1`), а 3a `Index.vue` sex-фильтр слал `male`/`female`, `sexLabel` в
`UsersTable.vue` мапит и `male`/`female`, и `m`/`f`. Это расхождение.
- Определи КАНОН из реального self-профиля (сверь профиль-Vue форму + `ProfileResource` + как значения пишутся) —
  вероятно `m`/`f`.
- Приведи к канону: admin Create/Edit sex-select, 3a `Index.vue` sex-фильтр опции, `sexLabel` в `UsersTable.vue`.
- Поправь тесты 3a, если они использовали неканоничные значения (`male`/`female` при `max:1`).
- Цель: одно значение sex по всему ядру (профиль = admin-форма = фильтр = лейбл).

---

## Definition of Done (3b)

- [ ] Миграция + модель `UserSocialLink` + связь `socialLinks()` + whitelist платформ (один источник).
- [ ] StoreUserRequest/UpdateUserRequest: middlename/sex/birth_date/password confirmed/social_links (+ http(s)-only url).
- [ ] UserController store/update: fill demographic + sync social_links (gated-safe: без ключа не трогать).
- [ ] Create.vue/Edit.vue: middlename/sex(gated)/birth_date(gated)/password_confirmation/social-виджет(gated);
      Edit — verify-manage email+phone (переиспользуя 3a-эндпоинты, ядровый confirm).
- [ ] SocialLinksField.vue + SocialIcon.vue; barrel-экспорт.
- [ ] Resource social_links (gated + в full()); index грузит связь только при токене; UsersTable social-колонка
      (rel=noopener, per-platform SVG, 'social' в OPTIONAL_TOKENS).
- [ ] sex-значения согласованы по всему ядру (под-задача 8).
- [ ] i18n en+uk.
- [ ] Pest + vitest зелёные; Pint на изменённых PHP.
- [ ] Имя коммита отдано (одна строка, английский). Тег НЕ вешать (в конце всей Ф3).

Дату выполнения впиши в шапку. Отчёт: изменённые/новые файлы, ключевые решения (sync-стратегия social, url-XSS-защита,
канон sex + что пришлось поправить в 3a), результат Pest/vitest (числа + финальный вывод), отклонения, имя коммита.
