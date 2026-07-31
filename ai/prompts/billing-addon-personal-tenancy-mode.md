# Промпт: аддон larafoundry-billing не работает в режиме tenancy `personal`

> **ВЫПОЛНЕНО 2026-07-27** (агент-дирижёр, реализация напрямую в `larafoundry-billing`).
> Части 1-3 сделаны + добавлено то, без чего Verify не проходит: миграция колонок состояния
> подписки на не-`companies` плательщика, трейт `Concerns\BillingSubscriber` (hasAccess для
> User-плательщика), атрибуция рефералов на `Registered` в personal, получатель чека и
> `user_id` платежа в personal. Тесты: 339 зелёных (было 313+1 падавший), Pint clean.
> НЕ выполнено: прогон миграций на MySQL — локальный сервер не запущен (см. отчёт).

## Контекст

Ядро `larafoundry v0.39.1`, аддон `larafoundry-billing v0.9.0`, хост — новый продукт Lorevid
(`D:\Development\lorevid\lorevid-host`, чистая установка по `docs/integrating-into-an-existing-app.md`).

Ядро поддерживает два режима арендатора: `larafoundry.tenancy.mode = teams | personal`. В `personal`
компаний нет вовсе — `LaraFoundryTenancy::activeCompany()` возвращает `null`, `companyList()` пуст,
а `BelongsToTenant` изолирует строки по `user_id` вместо `company_id`
(`src/Tenancy/Concerns/BelongsToTenant.php`, ≈ строка 26).

**Наблюдение владельца, с которым мы согласны:** платящим субъектом не обязана быть организация.
Продукт в режиме `personal` — это обычный B2C, где подписку оплачивает сам пользователь, и такой
продукт должен уметь пользоваться платным аддоном. Сейчас не умеет: аддон предполагает, что субъект
платежа — всегда `Company`.

Это не срочная бага (в `personal` у нас пока крутится Comentor со своей валютой, а Lorevid стоит на
`teams`), но пробел архитектурный: он молча сужает область применимости платного пакета вдвое.

Ниже — что именно мешает, по коду, и что предлагается сделать. Объём меньше, чем кажется на первый
взгляд: ядро аддона уже почти mode-agnostic, ломается периферия.

---

## Что уже сделано правильно (не трогать)

1. **Модель плательщика конфигурируема.** `config/larafoundry-billing.php`:

   ```php
   'billable_model' => env('LARAFOUNDRY_BILLING_BILLABLE_MODEL', Company::class),
   'paddle_billable_model' => env('LARAFOUNDRY_BILLING_PADDLE_BILLABLE_MODEL', Company::class),
   ```

   То есть хост уже может подставить свой класс — в `personal` это был бы `User`.

2. **Драйверы шлюзов резолвят плательщика по ключу арендатора, а не по компании.**
   `src/Gateways/CashierStripeDriver.php` ≈ строка 216 и `CashierPaddleDriver.php` ≈ строка 272:

   ```php
   $model = (new $class)->newQuery()->whereKey($tenant->getTenantKey())->first();
   ```

   `getTenantKey()` в `personal` отдаёт id пользователя, значит этот путь уже работает в обоих режимах.

3. **Колонки-ссылки на арендатора объявлены «плоскими»** (`unsignedBigInteger`, без `constrained()`),
   с явным комментарием, что аддон не привязывается к таблицам хоста. Это тоже помогает.

Вывод: замысел изначально был mode-agnostic, но три места его не выдержали.

---

## Часть 1. Миграция Stripe-колонок прибита к таблице `companies`

### Сейчас

`database/migrations/2026_06_04_000100_add_stripe_columns_to_companies_table.php`:

```php
if (! Schema::hasTable('companies')) {
    return;
}

Schema::table('companies', function (Blueprint $table) {
    if (! Schema::hasColumn('companies', 'stripe_id')) {
        $table->string('stripe_id')->nullable()->index();
    }
    // …
});
```

Имя таблицы зашито. В режиме `personal` таблица `companies` существует (её создаёт ядро), но пуста и
не используется, а Cashier-колонки нужны на `users` — иначе `Billable` на пользователе не найдёт ни
`stripe_id`, ни полей подписки.

То же относится к `2026_06_05_002100_add_billing_period_to_companies_table.php`.

### Нужно

Обе миграции должны навешивать колонки на таблицу **сконфигурированной** billable-модели, а не на
литерал `companies`:

```php
$model = config('larafoundry-billing.billable_model');
$table = (new $model)->getTable();

if (! Schema::hasTable($table)) {
    return;
}

Schema::table($table, function (Blueprint $t) use ($table) {
    if (! Schema::hasColumn($table, 'stripe_id')) { … }
});
```

Учесть Paddle: `paddle_billable_model` может отличаться от `billable_model` (это заявлено в
`Concerns/BillingCustomerPaddle.php`), значит paddle-миграции должны читать свой ключ конфига.

Обратная совместимость сохраняется: при дефолтном значении конфига таблица та же самая, `companies`.

---

## Часть 2. HTTP-контроллеры резолвят субъекта через `getActiveCompany()`

### Сейчас

Найдено два места (могут быть ещё — искать по `getActiveCompany`):

- `src/Http/Controllers/BlockedController.php` ≈ строка 53
- `src/Http/Controllers/ServicePaymentController.php` ≈ строка 235

```php
if ($user === null || ! method_exists($user, 'getActiveCompany')) {
    // …
}
$company = $user->getActiveCompany();
```

В `personal` `getActiveCompany()` всегда `null` — страница блокировки и оформление платежа
разваливаются ещё до того, как дело дойдёт до шлюза.

### Нужно

Один шов, который знает про режим, и все обращения — через него. Например
`Support\BillingSubject::current(): ?Model`:

```php
// Resolves who pays for the current request: the active company in `teams`
// mode, the signed-in user in `personal`. Returns null for a guest, or when a
// teams-mode user has no active company yet.
public static function current(): ?Model
{
    $user = auth()->user();
    if ($user === null) {
        return null;
    }

    if (config('larafoundry.tenancy.mode') === 'personal') {
        return $user;
    }

    return method_exists($user, 'getActiveCompany') ? $user->getActiveCompany() : null;
}
```

Дальше заменить оба вызова на `BillingSubject::current()`. Ключ для записи в таблицы брать
`->getTenantKey()`, а не `->getKey()` — так же, как это уже делает
`Actions/RedeemPromoCodeAction.php` ≈ строка 81.

---

## Часть 3. Партнёрка жёстко привязана к компании

### Сейчас

`database/migrations/2026_06_10_000200_create_referrals_table.php` ≈ строка 40:

```php
$table->unsignedBigInteger('referred_company_id')->unique();
```

Колонка `NOT NULL` и уникальная; в `personal` туда нечего писать, кроме id пользователя. То же в
`affiliate_commissions.company_id` (≈ строка 49, тоже `NOT NULL`) и в `company_payments.company_id`
(`2026_06_05_002000`, ≈ строка 49).

### Нужно

Семантику колонки поменять с «id компании» на **«ключ арендатора»** — в `teams` это id компании, в
`personal` id пользователя. Кода это почти не касается: значение и так приходит из `getTenantKey()`.

**Переименовывать колонки не нужно** — это ломающее изменение для уже стоящих установок (kohana.io,
Comentor). Достаточно:

1. обновить docblock каждой миграции и модели: колонка хранит ключ арендатора, а не обязательно id
   компании;
2. проверить, что все места записи идут через `getTenantKey()`, а не через `Company::getKey()`;
3. в админ-экранах, где значение показывается пользователю («Company»), выводить подпись по режиму —
   иначе оператор в `personal` увидит «Company #17» вместо имени человека.

Переименование в `tenant_id` уместно в следующей мажорной версии, отдельным решением.

---

## Verify

Проверять в обоих режимах — именно параллельность и есть суть задачи.

1. `LARAFOUNDRY_TENANCY_MODE=teams`, `billable_model` = Company-подкласс хоста: весь существующий
   набор тестов аддона (~30 файлов) зелёный, поведение не изменилось.
2. `LARAFOUNDRY_TENANCY_MODE=personal`, `billable_model` = `User`-класс хоста с трейтом
   `BillingCustomer`:
   - миграции проходят на чистой БД, Cashier-колонки оказались на `users`, а не на `companies`;
   - страница тарифов открывается у обычного пользователя без организации;
   - оформление подписки доходит до шлюза (в тесте — фейковый драйвер) и создаёт строку
     `company_payments` с `company_id` = id пользователя и `user_id` = его же id;
   - вебхук успешной оплаты зеркалит состояние подписки в колонки `users`;
   - middleware проверки доступа пускает оплатившего и не пускает неоплатившего;
   - страница блокировки рендерится, а не падает на `null`.
3. Погашение промокода в `personal`: строка `promo_code_redemptions` создаётся, повторное погашение
   тем же пользователем отбивается.
4. Партнёрская ссылка в `personal`: реферал регистрируется, комиссия начисляется, `referrals`
   уникальность не конфликтует.
5. Миграции проверить на MySQL, а не только на sqlite: `Schema::table` по имени из конфига — ровно
   тот класс изменений, где sqlite-тесты не ловят схемные различия.

---

## Что НЕ делать

- Не переименовывать `company_id` / `referred_company_id` в этой задаче — сломает стоящие установки.
- Не навешивать Cashier-трейт `Billable` на модели ядра: аддон платный, ядро должно остаться
  gateway-agnostic, это уже зафиксировано в `Concerns/BillingCustomer.php`.
- Не делать `billable_model` полиморфным (`billable_type` + `billable_id`): в одном приложении режим
  всегда один, две таблицы плательщиков одновременно не нужны, а полиморфизм утянет за собой индексы
  и запросы отчётности.
- Не трогать логику шлюзов — они уже режим-агностичны (`whereKey($tenant->getTenantKey())`).

---

## Известный второй пробел (отдельным промптом, не здесь)

`PlanContract::features(): array` + `PlanEntitlementResolver::allows()` дают **булевы** entitlements
(`in_array`). Для тарифов с числовыми квотами (N категорий, N брендов, N отчётов в месяц) этого не
хватает. Промпт по этому пробелу будет написан после пилотного подключения аддона в Lorevid, чтобы
отдать один точный список требований, а не гипотезу.
