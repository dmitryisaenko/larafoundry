# Промпт: Active sessions — показывать API-токен-устройства (мобилку), а не только web-сессии

## Контекст

Пакет `dmitryisaenko/larafoundry`, тег `v0.38.0`. Вкладка **Sessions** в self-service профиле
(`resources/js/Pages/Profile/sections/SessionsManager.vue`) показывает «Active sessions». Список строится в
`src/Profile/Http/Controllers/ProfileController.php` (~33-46) из **`$user->sessions()`** — то есть **только из
таблицы `sessions`** (web-браузерные сессии, драйвер database).

### Проблема

Host-приложение (Comentor) имеет мобильное приложение, которое авторизуется **Sanctum-токеном**
(`personal_access_tokens`, `$user->createToken($deviceName)`), а НЕ web-сессией. Такие входы **не попадают** в
таблицу `sessions`, поэтому в «Active sessions» мобильных устройств не видно — пользователь с ноутом и телефоном
видит только ноут. Для продукта с web+mobile это дыра в прозрачности («где я залогинен»).

### Задача

«Active sessions» должен объединять **два источника**: web-сессии (как сейчас) **и** активные API-токен-устройства
(`$user->tokens()`), с возможностью **ревокнуть** каждую строку по отдельности. Именование токенов — забота host'а
(Comentor шлёт человекочитаемый `device_name` вида «Android · SM-M127F»); ядро просто показывает `token.name`.

---

## Часть 1. Бэкенд — объединённый список в ProfileController

**Сейчас** ([ProfileController.php](../../src/Profile/Http/Controllers/ProfileController.php) ~33-46):
```php
$currentSessionId = $request->session()->getId();
$sessions = method_exists($user, 'sessions')
    ? $user->sessions()->orderByDesc('last_activity')->get()->map(fn ($session) => [
        'id' => $session->id,
        'user_agent' => $session->user_agent,
        // ...
        'last_activity' => optional($session->last_activity)->toIso8601String(),
        'is_current' => $session->session_id === $currentSessionId,
    ])
    : collect();
```

**Нужно:** добавить второй источник — токен-устройства — и отдать единый список с дискриминатором `type`.
```php
$webSessions = /* существующий map, каждому добавить 'type' => 'session' */;

// API-token devices (mobile / other API clients). Sanctum updates last_used_at per
// authenticated request; a token never used since issue falls back to created_at.
$tokenDevices = method_exists($user, 'tokens')
    ? $user->tokens()->orderByDesc('last_used_at')->get()->map(fn ($token) => [
        'id' => $token->id,
        'type' => 'token',
        'label' => $token->name,                 // host-provided human label
        'user_agent' => null,
        'last_activity' => optional($token->last_used_at ?? $token->created_at)->toIso8601String(),
        'is_current' => false,                   // a web view is never "on" a token device
    ])
    : collect();

$sessions = $webSessions->concat($tokenDevices)
    ->sortByDesc('last_activity')->values();
```
`type` для web-строк — `'session'`; для токенов — `'token'`. Остальные поля web-строк не менять (фронт уже парсит
`user_agent` → «Chrome · Windows 10/11»).

## Часть 2. Бэкенд — ревок токен-устройства (новый роут + экшн)

Зеркалит IDOR-паттерн `SessionController::destroy` ([SessionController.php](../../src/Auth/Http/Controllers/SessionController.php) ~60-78): route-model-bind по id, ownership-check с 404 на чужой/несуществующий.

- Экшн (в `SessionController` или рядом):
```php
public function destroyToken(Request $request, int $tokenId): RedirectResponse
{
    $user = $request->user();
    abort_if($user === null, 403);

    // Resolve strictly within the caller's own tokens → foreign/missing both 404 (no IDOR probe).
    $token = $user->tokens()->whereKey($tokenId)->first();
    abort_if($token === null, 404);

    $token->delete();

    return back()->with('status', __('larafoundry::profile.sessions.revoked'));
}
```
- Роут (там же, где `profile.sessions.destroy`, и в admin.php-группе, если self-service профиль под ней — см.
  как зарегистрированы существующие `profile/sessions/{session}` и `profile/sessions/others`). Имя, напр.,
  `profile.sessions.destroy-token`, путь `profile/tokens/{token}` (whereNumber). Порядок литералов/параметров —
  как у сессий.

## Часть 3. Фронт — SessionsManager.vue рендерит оба типа

**Сейчас** список рисует только web-сессии (иконка/парсинг user_agent) + кнопка ревока сессии.

**Нужно:** различать `item.type`:
- `type === 'session'` — как сейчас (парс user_agent, «this device» для `is_current`, ревок на
  `profile.sessions.destroy`).
- `type === 'token'` — иконка телефона/устройства, заголовок = `item.label` (host-строка, напр. «Android ·
  SM-M127F»), подпись «Last active {relative last_activity}», кнопка «Log out this device» → `router.delete` на
  `profile.sessions.destroy-token` с `item.id`.
- Кнопку ревока показывать для всех строк, КРОМЕ `is_current` (там бейдж «this device», как сейчас).

Мелкие i18n-ключи (frontend): «Mobile device», «Log out this device» (если ещё нет). Значения — English-as-key,
без длинных тире и бэктиков.

## Часть 4. i18n бэкенда

Переиспользовать существующий `larafoundry::profile.sessions.revoked` для успеха ревока токена (та же семантика).
Новых серверных ключей, скорее всего, не нужно.

---

## Verify

1. Залогиниться в web + выпустить Sanctum-токен (эмуляция мобилки: `$user->createToken('Android · SM-M127F')`).
   Открыть Sessions — видны **обе** строки: web-браузер (this device) и токен-устройство с его label и «last active».
2. Ревок токен-строки → её `personal_access_tokens` запись удалена; последующий API-запрос с тем токеном → 401.
   Список обновился (строки нет).
3. Ревок токена **чужого** юзера (подставить чужой id) → 404 (не 403, не удаление). Ревок несуществующего → 404.
4. Web-сессии и их ревок/«log out others» работают как раньше (регрессий нет). «this device» — только у текущей
   web-сессии; токен-строки без бейджа «this device».
5. `last_used_at = null` (свежий, ни разу не использованный токен) → показывается `created_at`, не пусто.
6. `composer lint` + `composer test` зелёные; сборка фронта host-приложения проходит.

## Связанные файлы

- [src/Profile/Http/Controllers/ProfileController.php](../../src/Profile/Http/Controllers/ProfileController.php)
- [src/Auth/Http/Controllers/SessionController.php](../../src/Auth/Http/Controllers/SessionController.php)
- [resources/js/Pages/Profile/sections/SessionsManager.vue](../../resources/js/Pages/Profile/sections/SessionsManager.vue)
- `routes/*.php` — где зарегистрированы `profile/sessions/*`
- Тесты: где покрыт `SessionController::destroy` — добавить кейсы для `destroyToken`.

## Что НЕ делать

- НЕ менять механизм web-сессий/`SessionInvalidator` — только ДОБАВить токен-источник и его ревок.
- НЕ придумывать имена токенов в ядре — показывать `token.name` как есть (host называет токены осмысленно).
- НЕ трогать admin-просмотр чужих профилей (`Admin/.../ProfileController`) в рамках этой задачи — только
  self-service `Profile`.
- НЕ ломать `is_current`/IDOR-семантику (чужой/несуществующий → 404).

## После реализации

- Ядро — репозиторий мейнтейнера. **Не** `git commit`/`tag`/`push`. Довести до зелёного (`composer lint` + `composer
  test`), прогнать `/security-review` + `/code-review`, затем **выдать имя коммита и semver-тег** (minor —
  новая фича: `v0.39.0`).
- В Comentor после тега: `composer update dmitryisaenko/larafoundry` (локально + lock, НЕ прод, НЕ `--force`),
  пересобрать фронт (`npm run build`) + закоммитить `public/build`. Мобильное приложение уже шлёт человекочитаемый
  `device_name` (правка Comentor-фронта, парная к этому промпту).
