# Промпт: QrLoginPanel — восстановление после протухшего CSRF/сессии + чистое состояние ошибки

## Контекст

Пакет `dmitryisaenko/larafoundry`, текущий тег `v0.38.0`. Компонент кросс-девайс QR-входа на веб-логине:
`resources/js/components/auth/QrLoginPanel.vue`. Встраивается в host-страницу логина как вкладка, на `active`
делает `POST larafoundry.qr.generate`, рисует QR и поллит `POST larafoundry.qr.poll` до аппрува с телефона.

### Симптом (воспроизведено в host-приложении Comentor)

Пользователь открыл логин, развернул QR-вкладку, **долго не сканировал**. В панели появилась красная ошибка
«Could not generate a QR code. Please try again.», при этом **одновременно** остались видны заголовок-инструкция
«Scan this code with your phone to sign in.» и плейсхолдер «Generating QR code…» в рамке. Уход на другую страницу
(Register) и возврат на логин — **код сразу заработал**.

### Диагноз

`generate()` вызывается один раз на активацию вкладки. После долгого простоя **web-сессия/CSRF протухают**
(session lifetime), и первый `POST qr.generate` возвращает **419 (TokenMismatch/expired)** → `catch` → `error='qr_generate_failed'`.
Ключевой факт: **этот же 419-ответ Laravel стартует новую сессию и кладёт свежий `XSRF-TOKEN` cookie**. Поэтому
свежая загрузка страницы (уход/возврат) — или **простой повтор POST** — уже проходят: компонент на каждом POST
перечитывает токен из cookie (`xsrfToken()`), и второй запрос берёт уже валидный токен.

Отсюда две проблемы UX, которые надо устранить:
1. Пользователь вообще не должен видеть эту ошибку в типовом случае — первый сбой самовосстановим одним повтором.
2. Если ошибка всё же показывается — рядом **не должно быть** ни «Scan this code…», ни «Generating QR code…».
   Сейчас показываются все три сообщения разом (противоречивое состояние).

## Часть 1. Тихий авто-ретрай `generate()` (устраняет типовой случай)

**Сейчас** ([QrLoginPanel.vue](../../resources/js/components/auth/QrLoginPanel.vue), ~64-73):
```js
async function generate() {
    error.value = null;
    try {
        const data = await post('larafoundry.qr.generate');
        qrCode.value = data.qrCode;
    } catch {
        error.value = 'qr_generate_failed';
    }
}
```

**Нужно:** при первом сбое — один тихий повтор через короткую паузу (свежий XSRF-cookie из 419-ответа сделает его
успешным). Ошибку показываем только если **и повтор** не прошёл. Пока идёт ретрай — остаёмся в состоянии «генерация»
(не мигаем ошибкой).
```js
async function generate({ isRetry = false } = {}) {
    error.value = null;
    try {
        const data = await post('larafoundry.qr.generate');
        qrCode.value = data.qrCode;
    } catch {
        if (!isRetry) {
            // The failed POST refreshed the XSRF-TOKEN cookie (a 419 starts a fresh
            // session); a single retry with the new token normally succeeds. A short
            // delay lets the Set-Cookie land before we read it again.
            await new Promise((r) => setTimeout(r, 600));
            return generate({ isRetry: true });
        }
        error.value = 'qr_generate_failed';
    }
}
```
Кнопка «Try again» (Часть 2) вызывает `generate()` (снова с авто-ретраем).

## Часть 2. Чистое состояние ошибки (взаимоисключающие сообщения)

**Сейчас** (template, ~119-138): инструкция и плейсхолдер показываются всегда, ошибка — отдельным `<p>` снизу.

**Нужно:** когда `error` установлен — **скрыть** инструкцию «Scan this code…» и плейсхолдер «Generating QR code…»,
показать только сообщение + кнопку повтора. Три взаимоисключающих состояния рамки: `qrCode` → картинка;
`error` → ошибка+кнопка; иначе → «Generating…».
```html
<div class="flex flex-col items-center gap-4">
    <!-- Instruction only in the normal (non-error) state. -->
    <p v-if="!error" class="text-sm text-ink-soft">
        {{ $t('Scan this code with your phone to sign in.') }}
    </p>

    <div class="flex h-52 w-52 items-center justify-center rounded-md border border-border bg-surface p-2">
        <img
            v-if="qrCode"
            :src="'data:image/svg+xml;base64,' + qrCode"
            :alt="$t('Sign-in QR code')"
            class="h-full w-full"
        />
        <div v-else-if="error" class="flex flex-col items-center gap-3 px-3 text-center">
            <p class="text-sm text-ink-soft">{{ $t('Could not load the QR code.') }}</p>
            <button
                type="button"
                class="cursor-pointer text-sm font-medium text-brand-600 hover:text-brand-700"
                @click="generate()"
            >
                {{ $t('Try again') }}
            </button>
        </div>
        <span v-else class="text-sm text-ink-soft">{{ $t('Generating QR code…') }}</span>
    </div>
    <!-- The standalone red <p> error line is removed (folded into the box above). -->
</div>
```
Примечание: старый текст-ключ `Could not generate a QR code. Please try again.` можно оставить в словаре
(осиротеет) или удалить; в новом состоянии используется более мягкий `Could not load the QR code.` + `Try again`.

## Часть 3 (опционально, hygiene). Периодическое обновление QR

`poll()` не перегенерирует код — долго висящий QR может протухнуть по TTL на бэке (`config qr.token_ttl`),
и поздний скан не сработает. Опционально: пока `active`, регенерировать QR по таймеру (интервал < TTL, напр. из
нового конфиг-значения `qr.refresh_interval_ms`, дефолт ~50000), очищая таймер в `stopPolling`/`onBeforeUnmount`.
Не обязательно для этого фикса — Части 1-2 закрывают заявленный симптом; отметить как отдельный маленький шаг.

## Часть 4. Словарь

Ядро несёт `lang/frontend/en.json` и `uk.json` (ru — на стороне host-приложения). Добавить ключи (English-as-key,
без длинных тире и бэктиков в значениях):

| Ключ | en | uk |
|---|---|---|
| `Try again` | Try again | Спробувати ще раз |
| `Could not load the QR code.` | Could not load the QR code. | Не вдалося завантажити QR-код. |

(Host-приложения, использующие ru, добавят переводы этих ключей у себя — это уже не в ядре.)

## Verify

1. Открыть логин, развернуть QR-вкладку — QR генерируется, «Scan this code…» видна, ошибки нет.
2. **Эмуляция протухшего CSRF:** испортить cookie `XSRF-TOKEN` в DevTools → развернуть вкладку заново (или нажать
   «Try again»). Первый POST даёт 419, тихий ретрай проходит → QR появляется, **ошибку пользователь не видит**.
3. **Жёсткий сбой** (замокать оба POST на 419/500): показывается ТОЛЬКО «Could not load the QR code.» + «Try again»;
   «Scan this code…» и «Generating QR code…» НЕ видны одновременно с ошибкой.
4. «Try again» повторяет генерацию (с авто-ретраем); при успехе показывает QR.
5. Поллинг и навигация домой после аппрува с телефона не сломаны. Таймеры чистятся при скрытии вкладки/unmount.
6. `composer lint` (Pint) и сборка фронта host-приложения проходят; `$t`-ключи присутствуют в en/uk.

## Связанные файлы

- [resources/js/components/auth/QrLoginPanel.vue](../../resources/js/components/auth/QrLoginPanel.vue)
- [lang/frontend/en.json](../../lang/frontend/en.json)
- [lang/frontend/uk.json](../../lang/frontend/uk.json)
- [config/larafoundry.php](../../config/larafoundry.php) — блок `qr` (только если делать Часть 3)

## Что НЕ делать

- НЕ трогать бэкенд QR-флоу (`qr.generate`/`qr.poll` контроллеры/роуты) — проблема чисто клиентская (восстановление
  после протухшего токена + состояние UI).
- НЕ убирать проверку `X-XSRF-TOKEN`/`credentials: same-origin` — она нужна.
- НЕ городить бесконечный цикл ретраев — ровно ОДИН авто-повтор, дальше явная кнопка.
- Часть 3 — опциональна; не тянуть, если не решено делать конфиг-значение.

## После реализации

- Ядро — репозиторий мейнтейнера. **Не** запускать `git commit`/`tag`/`push`. Довести до зелёного (`composer lint`),
  прогнать `/security-review` + `/code-review`, затем **выдать имя коммита и semver-тег** (это патч: `v0.38.1`).
- В host-приложении (Comentor) после выхода тега: `composer update dmitryisaenko/larafoundry` (локально + lock, НЕ на
  проде, НЕ `--force`), затем пересобрать фронт (`npm run build`) и закоммитить `public/build`. Деплой инициирует
  владелец.
