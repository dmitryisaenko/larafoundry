# Промпт: две находки при установке ядра в ЧИСТОЕ приложение

## Контекст

Ядро впервые ставилось не в существующее приложение, а в свежее (`composer create-project
laravel/laravel`) — новый продукт Lorevid, `D:\Development\lorevid\lorevid-host`. Шли строго по
`docs/integrating-into-an-existing-app.md` (§2 установка, §6 middleware, §7 shared props, §12
фронтовая обвязка, §4.1 трейты `User`). Версия ядра — `v0.39.1`, аддон `larafoundry-billing v0.9.0`.

Итог: интеграция рабочая (6 тестов зелёные, `pint` чист, `npm run build` собирается), но **две вещи
не дали пройти путь из документации без ручных обходов**. Ни то, ни другое я в ядре не правил —
контур ядра ваш.

Ниже обе находки: первая — реальный пробел упаковки ядра, вторая — регрессия самого Laravel,
о которой ядру стоит знать, потому что она делает заявленный диапазон версий неверным.

---

## Часть 1. Тег `larafoundry-pages` публикует не всё, что нужно опубликованным страницам

### Сейчас

`LaraFoundryServiceProvider` (≈ строка 970) публикует по тегу `larafoundry-pages` ровно одну папку:

```php
$this->publishes([
    __DIR__.'/../resources/js/Pages' => resource_path('js/Pages'),
], 'larafoundry-pages');
```

Но часть опубликованных страниц импортирует соседей **относительными путями, выходящими за
`Pages/`** — то есть указывает на папки, которые остались в пакете и в хост не попали:

- `resources/js/Pages/Profile/ProfileHub.vue` → `import SettingsForm from '../../components/settings/SettingsForm.vue'`
- `resources/js/Pages/Profile/sections/SessionsManager.vue` → `import { useDateFormat } from '../../../composables/useDateFormat.js'`

В хосте после `vendor:publish --tag=larafoundry-pages` эти пути ведут в `resources/js/components/...`
и `resources/js/composables/...`, которых нет. `npm run build` падает:

```
[UNRESOLVED_IMPORT] Could not resolve '../../components/settings/SettingsForm.vue'
    in resources/js/Pages/Profile/ProfileHub.vue
[UNRESOLVED_IMPORT] Could not resolve '../../../composables/useDateFormat.js'
    in resources/js/Pages/Profile/sections/SessionsManager.vue
```

Vite-алиас из §12 тут не спасает: он резолвит **bare specifier** `@dmitryisaenko/larafoundry`, а это
относительные пути. В `comentor-back` (первый хост) сборка проходит только потому, что там эти две
папки лежат в `resources/js/` — их кто-то докопировал руками, и в документации этого шага нет.

### Нужно

Любой из трёх вариантов, на ваш выбор — я не знаю, какой лучше сочетается с планами по UI-фазам:

1. **Расширить тег** — публиковать вместе со страницами и то, что они импортируют:
   ```php
   $this->publishes([
       __DIR__.'/../resources/js/Pages' => resource_path('js/Pages'),
       __DIR__.'/../resources/js/components' => resource_path('js/components'),
       __DIR__.'/../resources/js/composables' => resource_path('js/composables'),
   ], 'larafoundry-pages');
   ```
   Просто, но дублирует в хосте то, что уже доступно через барель, и хост будет расходиться с
   пакетом при апгрейдах (перепубликовывать придётся все три папки, а не одну).

2. **Перевести эти импорты внутри страниц на bare specifier**, который уже резолвится алиасом:
   `import { SettingsForm } from '@dmitryisaenko/larafoundry'` — если компонент экспортируется
   барелем; иначе `'@dmitryisaenko/larafoundry/resources/js/components/settings/SettingsForm.vue'`
   (подпуть уже покрыт вторым алиасом из §12). Тогда тег остаётся однопапочным, а опубликованные
   страницы перестают зависеть от расположения соседей. Мне это кажется предпочтительным: страницы
   публикуются, а библиотека — нет, и импорт через барель ровно это и отражает.

3. Оставить как есть, но **дописать шаг в §2 и в чеклист §15** — что после `larafoundry-pages`
   нужно скопировать `components` и `composables`, и повторять это при каждом UI-апгрейде.

Что бы ни выбрали — стоит закрыть тестом или проверкой в CI: собрать чистое приложение, поставить
ядро строго по §2 и прогнать `npm run build`. Сейчас этот путь ломается, а поймать это можно только
руками.

### Как это обойдено на моей стороне (чтобы вы знали, что искать)

В `lorevid-host` папки скопированы из `vendor/` вручную, и в CLAUDE.md продукта записано, что после
апгрейда ядра их надо перекопировать. Это обход, а не решение.

---

## Часть 2. `SetLocale` падает на Laravel 13.21+ (регрессия фреймворка, но диапазон ядра врёт)

### Сейчас

`composer.json` ядра заявляет `illuminate/*: ^12.0 || ^13.0`. Свежее приложение получает **последний**
Laravel 13 (у меня — `v13.22.0`) и отдаёт **500 на первом же запросе**:

```
InvalidArgumentException: Items cannot be represented by a scalar value.
  Illuminate\Support\Arr::from(NULL)
  Illuminate\Support\Arr::last(NULL, NULL, NULL)          CookieJar.php:129
  Illuminate\Cookie\CookieJar->queued('locale', NULL, NULL)
  Illuminate\Cookie\CookieJar->hasQueued('locale')
  Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale->responseAlreadySetsCookie()   SetLocale.php:133
  Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale->handle()                      SetLocale.php:108
```

Механика, если разложить:

- `CookieJar::queued($key, $default = null, $path = null)` делает
  `$queued = Arr::get($this->queued, $key, $default)` — то есть для не поставленной куки
  возвращает **`null`**, а не `[]`, и передаёт этот `null` в `Arr::last($queued, null, $default)`.
- В **13.20** `Arr::last()` начинался с `if (is_null($callback)) { return empty($array) ? value($default) : array_last($array); }`
  — `empty(null) === true`, возвращался `default`, `hasQueued()` честно давал `false`.
- В **13.22** `Arr::last()` начинается с `$array = static::from($array);`, а `Arr::from(null)` бросает
  `InvalidArgumentException`.

Итого `CookieJar::hasQueued()` на 13.21+ падает **для любой ещё не поставленной куки** — это баг
фреймворка (`queued()` не должен подставлять `$default` в качестве дефолта для массива), и ядро
вызывает `hasQueued()` совершенно законно. Но следствие такое: ядро **не работает** на верхней
границе своего же заявленного диапазона, и любой новый хост натыкается на это сразу.

Показательно, что `composer.lock` самого ядра пинит `v13.20.0` — то есть на CI и в разработке ядра
эта версия никогда и не проверялась.

### Нужно

Решить, что честнее, и сделать одно из:

1. **Сузить диапазон** в `composer.json` ядра, пока апстрим не починит: `^12.0 || ~13.20.0`
   (или `>=13.0 <13.21`). Тогда composer сам не заведёт хост в сломанную версию — сейчас он это
   делает молча.
2. **Не зависеть от `hasQueued()`** в `SetLocale::responseAlreadySetsCookie()` — например читать
   `app('cookie')->getQueuedCookies()` и искать имя перебором, как строчкой выше это уже делается
   для `$response->headers->getCookies()`. Тогда ядро переживает регрессию независимо от версии,
   и диапазон трогать не надо. Похоже на меньшее из двух зол, если апстрим не спешит.
3. Плюс, независимо от выбора: **добавить в CI-матрицу ядра верхний Laravel 13** (`composer update
   --with laravel/framework:^13.0` без lock-файла). Сейчас матрица гоняет только PHP-версии, поэтому
   регрессия фреймворка ядром не ловится вообще.

### Как это обошёл я

В `lorevid-host` `laravel/framework` прибит к `~13.20.0` с объяснением в CLAUDE.md продукта.
Это пин на моей стороне; если ядро сузит диапазон или уйдёт от `hasQueued()`, я его отпущу.

---

## Что НЕ делать

- Ничего не менять в моём приложении (`D:\Development\lorevid\lorevid-host`) — обходы там осознанные
  и снимутся, когда ядро отреагирует.
- Часть 2 — **не баг ядра**, а регрессия Laravel. Не надо переписывать `SetLocale` целиком: вопрос
  только в одном вызове и в честности диапазона версий.
- Не менять поведение `SetLocale` по существу (какая локаль побеждает) — здесь речь исключительно
  о том, как он проверяет уже поставленную куку.

## После реализации

- Деплой и весь git делает владелец. Локальные коммиты — да, пуш и релиз — нет.
- Сервер / прод-БД / SSH — только через владельца: перечислить команды текстом и попросить вывод.
- Имена коммитов — plain-текст, по одной строке, строго на английском, без обрамления и без
  `git commit -m`.
- Если Часть 1 закрывается вариантом 2 (импорты через барель), это UI-затрагивающее изменение —
  отметьте в CHANGELOG, что хостам надо перепубликовать `larafoundry-pages` и пересобрать ассеты.
