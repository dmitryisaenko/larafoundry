# Промпт: ядровый `ConfirmDialog` + composable `useConfirm` (замена SweetAlert2)

> Создан: 2026-06-24 22:34
> Выполнен: _(агент дописывает дату/время выполнения)_

## Контекст

Пакет `larafoundry` (composer `dmitryisaenko/larafoundry`, namespace `Dmitryisaenko\LaraFoundry\`, фронт в `resources/js/`)
намеренно держится **без внешних UI-зависимостей** (донор тянул Dialog-примитив, которым не пользовался; SweetAlert2/iconify/SCSS
в host тоже исключены). Нужен **переиспользуемый диалог подтверждения** для всей экосистемы (host kohana.io, аддоны) —
generic, доменно-независимый, как `Swal.fire`, но свой и лёгкий.

Триггер: в host (`kohana.io`) удаление контрагента сейчас на `window.confirm` — некрасиво и не темится. Это же понадобится
на любой странице любого приложения на ядре, поэтому компонент идёт **в ядро** (как `UserAvatar`, `CompanyLogo`, `Modal` и UI-kit).

### Что уже есть в пакете (использовать, не дублировать)

- `resources/js/components/ui/Modal.vue` — базовый оверлей: Teleport + Transition, body scroll-lock (ref-count),
  Esc + backdrop + close-кнопка → единый emit `close`, **минимальный focus-trap по Tab**. Пропсы: `open`, `title`,
  `closeOnEsc`, `closeOnBackdrop`. Слоты: default (body), `footer`. **ConfirmDialog строить ПОВЕРХ Modal**, а не с нуля.
- `resources/js/composables/useT.js` — `useT()` возвращает `t` из vue-i18n для `<script setup>` (в шаблоне есть глобальный `$t`).
- `resources/js/index.js` — публичные экспорты (UI-kit: `InputField`/`TextareaField`/`SelectField`/`DateField`, и т.д.).
- Фронт-переводы: `lang/frontend/en.json` и `lang/frontend/uk.json` (плоский JSON).
- Тема пакета **нейтральная**: использовать ТОЛЬКО семантические токены (`--color-danger`, `--color-brand-*`,
  `--color-warning`, `--color-success`, `--color-surface`, `--color-ink*`, `--color-border`). Host перекрашивает токены
  (навы + тёмная тема) сам — НЕ хардкодить цвета.

## Что построить

### 1. Composable `resources/js/composables/useConfirm.js`

Промис-API в духе `Swal.fire`, синглтон на модульном уровне (одно общее состояние на приложение):

```js
import { reactive } from 'vue';

const state = reactive({ open: false, options: {}, _resolve: null });

/**
 * Open a confirm dialog. Resolves true on confirm, false on cancel/dismiss.
 * @param {object} options
 *   variant?: 'danger' | 'primary' | 'warning' | 'question'   (default 'primary')
 *   title?: string            already-translated heading
 *   message?: string          already-translated body line
 *   detail?: string           optional secondary line (muted)
 *   highlight?: string        optional bold line (e.g. the record name)
 *   confirmLabel?: string     defaults to t('Confirm') in the component
 *   cancelLabel?: string      defaults to t('Cancel') in the component
 * @returns {Promise<boolean>}
 */
export function confirm(options = {}) {
    state.options = options;
    state.open = true;
    return new Promise((resolve) => { state._resolve = resolve; });
}

// Internal: the ConfirmDialog component calls this to settle + close.
export function _settleConfirm(result) {
    state.open = false;
    const resolve = state._resolve;
    state._resolve = null;
    resolve?.(result);
}

export function useConfirmState() { return state; }
```

Замечания:
- **Строки уже переведённые** (вызывающий делает `t(...)`), компонент НЕ знает про i18n-ключи доменных строк — так
  composable остаётся generic. Дефолты кнопок (`Confirm`/`Cancel`) переводит сам компонент через `useT`.
- Синглтон → диалог монтируется **один раз** на приложение, вызывать `confirm()` можно откуда угодно.

### 2. Компонент `resources/js/components/ui/ConfirmDialog.vue`

- Строится поверх `Modal.vue` (`:open="state.open"` из `useConfirmState()`, `@close` → `_settleConfirm(false)`).
- Содержимое: **иконка по variant** (inline-SVG, цвет из токена), `title` (h-заголовок), `message`, опц. `highlight`
  (жирным), опц. `detail` (приглушённый). Footer: кнопка Cancel (нейтральная) + кнопка Confirm (цвет по variant).
- Маппинг variant → токен/иконка:
  - `danger` → `--color-danger`, иконка-предупреждение (треугольник/корзина-контекст). Кнопка Confirm красная.
  - `warning` → `--color-warning`, треугольник `!`.
  - `question` → `--color-brand`, «?».
  - `primary` (default) → `--color-brand`, инфо/галочка.
- Доступность: `role="alertdialog"`, `aria-labelledby` (title) + `aria-describedby` (message). Modal уже даёт Tab-trap и Esc.
  **Фокус по открытию ставить на кнопку Cancel** (для destructive безопаснее), и возвращать фокус триггеру по закрытию
  (если Modal этого не делает — добавить в ConfirmDialog через `watch(open)` + сохранённый `document.activeElement`).
- Esc / backdrop / Cancel → `_settleConfirm(false)`; Confirm → `_settleConfirm(true)`.
- Кнопки крупные, тач-дружелюбные (это используется и на мобайле): высота ~40px, читаемые лейблы.
- Тема: только токены, проверить light **и** dark (host включает `html.dark`).

### 3. Монтирование

Компонент управляется синглтоном, поэтому его надо вставить **один раз** в шелл приложения. В ядре:
- Добавить `<ConfirmDialog />` в `resources/js/layouts/AppLayout.vue` и `AdminLayout.vue` (рядом с системой оверлеев),
  чтобы приложения на ядровом шелле получали его «бесплатно».
- В документации явно указать: **хост со своим layout** (как kohana.io) должен сам отрендерить `<ConfirmDialog />`
  один раз в своём корневом layout.

### 4. Экспорт

В `resources/js/index.js` добавить:
```js
export { default as ConfirmDialog } from './components/ui/ConfirmDialog.vue';
export { confirm, useConfirmState } from './composables/useConfirm.js';
```

### 5. Переводы

В `lang/frontend/en.json` и `uk.json` добавить generic-ключи (доменные строки — на стороне вызывающего):
- `Confirm` → en `Confirm`, uk `Підтвердити`
- `Cancel` → (проверить, может уже есть) en `Cancel`, uk `Скасувати`
Проверить дубли перед добавлением.

## Качество / приёмка

- `npm run build` пакета (и/или type-check) зелёный; ESLint без ошибок (нет `v-html` на компонентах и т.п.).
- **Pest**: компонент чисто фронтовый (PHP нет) — юнит-тестировать нечем в Pest. Если в пакете есть JS-тест-харнес
  (vitest) — добавить смоук (открыть → confirm/cancel резолвит промис). Если нет — НЕ заводить ради этого харнес;
  зафиксировать, что покрытие будет в host-интеграции. Не писать пустых/фиктивных Pest-тестов.
- `/security-review` + `/code-review` по git-diff (как на каждый модуль ядра).
- **Документация** (правило: install/setup пакета → в доку): обновить integration-гайд + readme + `docs/README` —
  описать `confirm()` API, варианты, и требование смонтировать `<ConfirmDialog />` один раз (для хостов со своим layout).

## Версия / git

- Bump **minor** (новый публичный компонент), тег по semver. **Commit/tag/push делает Дмитрий** — агент даёт имя коммита.
- Предлагаемое имя коммита (англ., plain-text):
  `Add reusable ConfirmDialog component and useConfirm() promise API (neutral-themed, no external dialog lib)`

## Host-интеграция (ОТДЕЛЬНО, после тега ядра — НЕ в этом промпте)

После выхода тега обновить `kohana.io`:
- `resources/js/Layouts/AppLayout.vue` — отрендерить `<ConfirmDialog />` один раз.
- `resources/js/Pages/Counterparties/Index.vue` — заменить `window.confirm` в `destroy(c)` на
  `await confirm({ variant:'danger', title: t('Delete counterparty'), message: t('Are you sure you want to delete this counterparty?'), highlight: c.display_name, confirmLabel: t('Delete'), cancelLabel: t('Cancel') })`.
  Добавить host-переводы uk: `Delete counterparty` → `Видалити контрагента`,
  `Are you sure you want to delete this counterparty?` → `Ви впевнені, що хочете видалити цього контрагента?`.
- Прогнать host-тесты + `npm run build`.
