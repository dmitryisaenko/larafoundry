# Промпт: нативная форма logout в ядре (замена Inertia-Link)

- **Создан:** 2026-07-20 13:41
- **Выполнен:** _(впиши дату+время после выполнения)_
- **Проект:** ЯДРО `larafoundry` (composer `dmitryisaenko/larafoundry`, namespace `Dmitryisaenko\LaraFoundry\`)
- **Тип:** bugfix (frontend) + контракт в integration-гайд
- **Запросил:** Дмитрий (баг воспроизведён в host kohana.io)

---

## 1. Проблема и корневая причина (обязательно прочитать)

В host `kohana.io` выход из админки открывает **модальный overlay-ошибки Inertia**
(«All Inertia requests must receive a valid Inertia response») вместо чистого разлогина.
Пользователь видит это как «попап-окно».

Механика:

1. Кнопка «Выйти» в админке — это ядровый `components/admin/OperatorPullout.vue`, где logout
   сделан через `<Link href="/logout" method="post">`, то есть **Inertia-визит** (XHR с `X-Inertia`).
2. Fortify чистит сессию и делает `302 → /`.
3. Гостевой `/` в kohana.io — **server-rendered Blade-лендинг (SEO-first), НЕ Inertia-страница**.
4. Inertia-клиент ждёт валидный Inertia-ответ, получает голый HTML лендинга → рендерит его
   в модальном iframe-оверлее. Это и есть «попап».

**Почему у другого хоста (Comentor) чисто:** там целевой роут после logout — Inertia-страница,
поэтому Inertia получает валидный ответ и переходит штатно. Разница не в ядре, а в том, что у
kohana.io гостевой `/` намеренно не-Inertia.

**Фикс:** logout должен быть **нативной формой** (`<form method="POST" action="/logout">`),
а не Inertia-визитом. Нативный submit — full-page navigation: браузер сам следует за `302 → /`,
Inertia в это не вмешивается → попапа нет. Host `kohana.io` уже сделал это у себя
(`resources/js/Components/LogoutForm.vue`) — зеркалим тот же паттерн внутрь ядра.

---

## 2. Контракт CSRF-меты (уже проверен, host его выполняет)

Нативная форма не проходит через Inertia/axios, поэтому CSRF-токен надо взять из DOM:
```
document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
```
Дмитрий проверил: host kohana.io отдаёт `<meta name="csrf-token" content="...">` в
`resources/views/app.blade.php:14`. Значит ядровая нативная форма токен возьмёт.
**Этот контракт надо задокументировать** (см. §5) — host ОБЯЗАН рендерить csrf-мету в своём
blade-шелле, иначе logout из ядровых страниц сломается (419).

---

## 3. Создать `resources/js/components/LogoutForm.vue`

Зеркало host-версии (`kohana.io/resources/js/Components/LogoutForm.vue`), но по ядровым
конвенциям (папка `components/` строчная, экспорт из barrel). Содержимое:

```vue
<script setup>
/**
 * Logout via a NATIVE form POST (full-page reload), not an Inertia visit.
 *
 * An Inertia logout stays inside the SPA: after the session is cleared the server
 * redirects to the guest landing, and if that landing is a plain (non-Inertia)
 * response — as SEO-first host landings are — Inertia has no valid response to
 * render and pops its error-modal overlay. A native form submit is a full-page
 * navigation: the browser follows the 302 and reloads from scratch, so Inertia
 * never intercepts the logout. See ai/prompts/logout-native-form-fix.md.
 *
 * CSRF: the token is read from `<meta name="csrf-token">`, which the host shell
 * MUST render (documented contract). The default slot is the button's inner
 * content; `buttonClass` styles the button. `display:contents` keeps the wrapping
 * form out of the parent layout.
 */
defineProps({
    buttonClass: { type: String, default: '' },
});

const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
</script>

<template>
    <form method="POST" action="/logout" class="contents">
        <input type="hidden" name="_token" :value="csrf">
        <button type="submit" :class="buttonClass">
            <slot />
        </button>
    </form>
</template>
```

**Экспортировать из barrel** `resources/js/index.js` (рядом с `AuthCard`, `AppFlashMessage` и др.),
чтобы страницы импортировали `import { LogoutForm } from '@dmitryisaenko/larafoundry'`
(так же, как импортируется `AppBaseLayout`).

---

## 4. Заменить все 4 Inertia-Link-логаута на `<LogoutForm>`

Сохрани текущие классы/лейбл/иконку у каждой кнопки (визуально не меняем). Слот — внутренность
кнопки (лейбл `{{ $t('Log out') }}` + иконка где есть), `button-class` — прежний класс `<Link>`.
Убери ставший ненужным импорт `Link`, если он больше нигде на странице не используется.

1. `resources/js/Pages/Legal/Accept.vue:50` — `<Link href="/logout" method="post" as="button" class="text-sm text-ink-soft hover:text-ink">`.
2. `resources/js/Pages/Auth/VerifyEmail.vue:58-65` — тот же класс `text-sm text-ink-soft hover:text-ink`.
3. `resources/js/Pages/Auth/UserBlocked.vue:43-50` — класс `rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600`.
4. `resources/js/components/admin/OperatorPullout.vue:136-144` — класс с иконкой SVG внутри; **иконку оставить внутри слота** `<LogoutForm>`, класс перенести в `button-class`.

Пример трансформации (Accept.vue):
```vue
<!-- было -->
<Link href="/logout" method="post" as="button" class="text-sm text-ink-soft hover:text-ink">
    {{ $t('Log out') }}
</Link>
<!-- стало -->
<LogoutForm button-class="text-sm text-ink-soft hover:text-ink">
    {{ $t('Log out') }}
</LogoutForm>
```
Импорт на каждой странице: `import { LogoutForm } from '@dmitryisaenko/larafoundry';`
(либо относительным путём, как принято на этой конкретной странице — сверься с её соседними импортами).

После правок в ядре **не должно остаться** ни одного `href="/logout"` через `<Link>`
(проверь `grep -rn "/logout" resources/js` — все вхождения logout должны быть только внутри `LogoutForm.vue`).

---

## 5. Контракт в integration-гайд

В `docs/integrating-into-an-existing-app.md` добавить пункт в раздел требований к host-шеллу
(рядом с layout/HandleInertiaRequests-требованиями, ~§ про layout caveat, строки 170-173):

> **CSRF-мета обязательна.** Ядровые страницы логаутятся нативной формой (`LogoutForm`),
> чтобы не спотыкаться об Inertia-оверлей на не-Inertia гостевом лендинге. Форма читает токен из
> `<meta name="csrf-token" content="{{ csrf_token() }}">`. Host ОБЯЗАН рендерить эту мету в
> `<head>` своего blade-шелла (`app.blade.php`), иначе POST `/logout` вернёт 419.

Если правки install/setup затрагивают контракт host — синхронизируй также readme пакета и
`docs/README` (правило: setup-контракты пакета живут в integration-гайде + readme + docs/README).

---

## 6. Тесты

**vitest** (`tests-js/LogoutForm.spec.js`, стиль как у соседних `*.spec.js`; `$t` стаблен в `tests-js/setup.js`):
- рендерит `<form method="POST" action="/logout">` c hidden `_token`;
- когда в `document.head` есть `<meta name="csrf-token" content="TOK">` → `_token` value = `TOK`
  (ставь мету в тесте перед mount, убирай после);
- когда меты нет → `_token` пустая строка (не падает);
- `buttonClass` попадает на `<button type="submit">`; слот рендерится внутри кнопки.
- Прогонять весь пакет vitest (`npm run test:js`) — правки Vue могли задеть снапшоты/импорты
  затронутых страниц (урок из прошлого: гонять vitest на любые Vue-правки ядра).

**Pest** (guard контрактного эндпоинта, от которого зависит нативная форма):
- `POST /logout` аутентифицированным пользователем инвалидирует сессию и делает redirect
  (гость после этого не имеет доступа к защищённому роуту). Если такой тест уже есть — убедиться,
  что он зелёный; если нет — добавить лёгкий Feature-тест.
- Полный прогон Pest должен остаться зелёным.

---

## 7. Ревью-гейт (реально, 2 агента по git-diff)

Порядок: довести до зелёного (vitest + Pest + Pint) → **2 агента ревью по фактическому git-diff**
(`/code-review` + `/security-review` угол: не сломан ли CSRF-контракт, нет ли XSS в подстановке
токена, не остался ли Inertia-Link-логаут) → починить замечания → и только потом коммит/тег/пуш
(их делает Дмитрий).

---

## 8. Host-интеграция (kohana.io) — после тега ядра

1. Bump `composer.lock` в kohana.io на новый тег ядра (`composer update dmitryisaenko/larafoundry`).
2. `npm run build` (НЕ `npm run dev`) — пересобрать ассеты host под обновлённое ядро.
3. Прогнать host-тесты (PHPUnit) — зелёные.
4. Вручную проверить: выход из **админки** kohana.io больше НЕ открывает попап, а чисто редиректит
   на лендинг; выход из тенант-шелла тоже чист.

---

## 9. Гардрейлы

- **Git — Дмитрий сам.** Агент НЕ вызывает `git commit/tag/push` (даже локально). Довести до
  зелёного и отдать **имена коммитов** (отдельная строка на проект) + предложить semver-тег ядра.
- Тег вешается **только на ядро** (`larafoundry`). Host — просто коммит без тега.
- Визуально ядро не меняем: те же классы, лейблы, иконки.
- `npm run dev` в kohana.io запрещён — только `npm run build` в конце.

## 10. Ожидаемые артефакты (что отдать в конце)

- Имя коммита ядра (напр. `Replace Inertia-Link logout with native LogoutForm to avoid Inertia error-modal on non-Inertia landing`).
- Предлагаемый тег ядра (patch-bump от текущего — уточни через `git tag`, НЕ из памяти).
- Имя коммита host kohana.io (напр. `Bump larafoundry core; rebuild assets for native logout form`).
- Краткий отчёт: 4 логаута переведены, тесты зелёные (числа vitest/Pest), ревью-замечания и фиксы,
  ручная проверка попапа.
