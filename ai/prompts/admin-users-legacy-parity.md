# Prompt: Admin "Users" console — legacy visual parity + reusable filter drawer

Создан: 2026-07-16 19:40 (Get-Date). Выполнено: 2026-07-16.

Заказчик хочет, чтобы core-экран `/admin/users` «со старта» выглядел как в боевом kohana_legacy:
плотные строки, действия — цветные иконки-кнопки (а не переносящийся список текст-ссылок),
фильтр — правая выезжающая панель, легаси-стиль пагинации. Эталон: `kohana_legacy/resources/js/pages/admin/...`.

Правим ТОЛЬКО ядро (`larafoundry`). Host-страница `Admin/Users/Index.vue` не меняет контракт (12 emit-событий
`UsersTable` сохраняются). Host-обвязка фильтра — отдельно, напрямую в kohana.io.

## 1. `resources/js/components/admin/UsersTableActions.vue`
Заменить текст-кнопки (`flex flex-wrap ... text-xs`, дающие высокие строки) на **один горизонтальный ряд
компактных квадратных иконок-кнопок** (SVG 18–20px, `rounded`, padding ~6–8px, `title`-тултип, `flex-nowrap`).
Набор и цвета — по легаси `AdminUsersTableBtnsRow.vue`/`_admin-users.scss`:
- ticket (create ticket) — outline, brand
- logs — emerald (bg emerald-600, white)
- edit — brand (bg brand-600/700, white) — только active (не blocked/deleted)
- verify/unverify email — иконка конверта (emerald/amber) — ядровая доп.фича, оставить
- verify/unverify phone (при опции `phone`) — иконка телефона
- block/unblock — amber (block: `#af5602`; unblock: outline+strike) — **block по-прежнему emit `block`**
  (host открывает `BlockUserDialog`; НЕ копируем легаси-хак с невидимым select)
- delete/undelete — rose (`#dc2626`)
- follow (impersonate) — slate (`#71717a`) — только active non-admin non-blocked
Контракт emit — без изменений. Тултипы — существующие i18n-ключи (Edit/Logs/Create ticket/Block/Unblock/
Delete/Restore/Follow/Verify email/…).

## 2. `resources/js/components/admin/UsersTable.vue`
Перекомпоновать под легаси-плотность (эталон `AdminUsersTable.vue`). Колонки:
`ID | avatar | Ім'я(+ причина блока/удаления + Super-admin) | Email/Телефон(+соц.иконки) | Реєстрація/Остання
активність | Comp./Empl. | Country | [Sex] | [Age] | Actions`.
- Email+Phone — в ОДНОЙ ячейке (email-строка + phone-строка, у каждой verify-иконка ✓/✗), соц-иконки под ними.
  Phone/social — опциональные токены (как сейчас), при выкл — только email.
- Реєстрація + остання активність — в одной ячейке (дата регистрации сверху, активность снизу; при
  `last_activity_stale` — активность в warning-цвете).
- Причина блока (`block_reason`) / удаления (`deleted_at_human`) — мелким текстом под именем.
- Тонировка строки: blocked → amber-подложка, deleted → rose-подложка.
- УБРАТЬ отдельные колонки Language, Auth, Status (легаси их не показывает; статус = тонировка+причина).
- Sex/Age — оставить опциональными колонками (легаси Стать/Вік).
- Сохранить host-seam `extra_columns` (рендерить после Age, перед Actions).

## 3. `resources/js/components/admin/AdminFilterDrawer.vue` (НОВЫЙ, экспорт из barrel)
Самодостаточная правая выезжающая панель (host рендерит сам; `AdminLayout` не трогаем):
- Props: `open` (Boolean, v-model), `title` (String). Emits `update:open`.
- `<Teleport to="body">`: оверлей (fade, клик закрывает) + панель справа (slide-in translateX), шапка
  (title + close-X, aria-label), `<slot>` тело (scroll, max-h), `<slot name="footer">` (кнопки Clear/Apply).
- Esc закрывает; scroll-lock body пока open; тема light/dark через существующие токены.

## 4. `resources/js/components/PagePaginator.vue`
- Скрывать полностью при **одной странице**: `v-if="p.last_page > 1"` (сейчас `p.total > 0` — показывает
  единственную «1»).
- Легаси-раскладка: слева диапазон `from … to`, по центру номера страниц (логика номеров уже есть), справа `total`.

## 5. `src/Admin/Http/Resources/AdminUserResource.php`
Добавить (аддитивно) для паритета строки:
- `blocked_at_human` = `user_blocked_at?->diffForHumans()` (для «x ago» у причины блока)
- `deleted_at_human` = `user_deleted_at?->diffForHumans()`
- `last_activity_stale` (bool) = `last_activity_at` старше порога `config('larafoundry.admin.recent_activity_days', 30)`
Добавить дефолт `recent_activity_days => 30` в `config/larafoundry.php` (секция admin).

## Тесты (Pest) + гейт
- Обновить/добавить ассерты в тест `AdminUserResource`/список юзеров: новые поля присутствуют, `last_activity_stale`
  корректно считается от порога.
- Компонентных снапшотов нет — визуал проверяет заказчик.
- Ревью-гейт: 2 агента по git-diff → фиксы → имя коммита + semver-тег.

Host-часть (отдельно, kohana.io): кнопка «Фільтри» → `AdminFilterDrawer` с радио-группами
(Registered/Recent activity/Email verified/Phone verified) + Age from/to + Clear/Show(N); query-ключи сохранить.
