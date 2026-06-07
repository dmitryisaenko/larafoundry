<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry legal pages (phase 5.3)
|--------------------------------------------------------------------------
| Реестр редактируемых правовых страниц (Terms / Privacy / Cookie policy).
| ЕДИНЫЙ источник истины (как реестр larafoundry-email.templates): editable
| ТОЛЬКО зарегистрированные здесь slug'и (fail-closed) — super-admin не может
| завести произвольный slug, а публичная страница `/legal/{slug}` отдаётся лишь
| для зарегистрированного slug'а.
|
| Поток данных:
|   реестр (этот файл) — ПЛЕЙСХОЛДЕР-ДЕФОЛТЫ: title / body_html по локалям.
|   larafoundry_legal_pages (БД) — ТОЛЬКО опубликованная super-admin'ом страница.
|   Нет БД-строки (или не опубликована) → публичная страница 404 (плейсхолдер
|   НИКОГДА не отдаётся как настоящий юр-текст — его сперва правит и публикует
|   владелец проекта под свою юрисдикцию).
|
| Добавить новую правовую страницу = +1 запись здесь (slug + дефолтный текст).
| UI-редактор подхватит её автоматически.
|
| body_html чистится email-friendly HTML-purifier'ом (общий HtmlSanitizer) на
| записи И на отдаче. Рендера переменных НЕТ — страницы статичны (в отличие от
| писем), что убирает этот класс уязвимостей.
|
| Контент юр-страниц host-специфичен (юрисдикция, текст юриста); ядро даёт лишь
| механизм + плейсхолдеры. Версия (`version`) бампится владельцем при
| существенном изменении и кормит re-accept gate (фаза 5.3 часть B).
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Cookie consent (phase 5.3 part B)
    |--------------------------------------------------------------------------
    | OFF by default: the core sets only strictly-necessary cookies (session /
    | XSRF / locale / appearance), which do NOT require consent. A host turns the
    | banner on only once it adds non-essential cookies (analytics/marketing).
    | Granularity is binary (accept all / necessary only); the decision is stored
    | in an encrypted cookie for guests and synced to the `cookie_consent` user
    | setting on login (the source of truth once authenticated, decision D-5.3-6).
    |
    | SECURITY: keep `cookie` under Laravel's cookie encryption — do NOT add it to
    | EncryptCookies::$except. Encryption is what makes a guest's decision tamper-
    | resistant; exempting it would let anyone forge an `accept` cookie.
    */
    'cookie_consent' => [
        'enabled' => false,
        'cookie' => 'larafoundry_cookie_consent',
        'lifetime_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Terms acceptance + re-accept gate (phase 5.3 part B)
    |--------------------------------------------------------------------------
    | `enforce` ON requires a registration-time checkbox AND re-acceptance when
    | the published Terms version is bumped. It is FAIL-OPEN: when the `slug` page
    | is not published (currentVersion is null) nothing is enforced, so the gate
    | never locks users out of a page that does not exist yet. `slug` chooses which
    | legal page drives the gate. `except_routes` / `except_prefixes` extend the
    | built-in bypass list (logout, the accept screen, legal pages, verification).
    */
    'terms' => [
        'enforce' => true,
        'slug' => 'terms',
        'except_routes' => [],
        'except_prefixes' => [],
    ],

    'pages' => [

        // Условия использования.
        'terms' => [
            'title' => [
                'en' => 'Terms of Service',
                'uk' => 'Умови використання',
            ],
            'body_html' => [
                'en' => '<p>Add your Terms of Service here. Edit and publish this page in the operator console before you launch.</p>',
                'uk' => '<p>Додайте сюди свої умови використання. Відредагуйте та опублікуйте цю сторінку в консолі оператора перед запуском.</p>',
            ],
        ],

        // Политика конфиденциальности.
        'privacy' => [
            'title' => [
                'en' => 'Privacy Policy',
                'uk' => 'Політика конфіденційності',
            ],
            'body_html' => [
                'en' => '<p>Add your Privacy Policy here. Edit and publish this page in the operator console before you launch.</p>',
                'uk' => '<p>Додайте сюди свою політику конфіденційності. Відредагуйте та опублікуйте цю сторінку в консолі оператора перед запуском.</p>',
            ],
        ],

        // Политика cookie.
        'cookies' => [
            'title' => [
                'en' => 'Cookie Policy',
                'uk' => 'Політика щодо файлів cookie',
            ],
            'body_html' => [
                'en' => '<p>Add your Cookie Policy here. Edit and publish this page in the operator console before you launch.</p>',
                'uk' => '<p>Додайте сюди свою політику щодо файлів cookie. Відредагуйте та опублікуйте цю сторінку в консолі оператора перед запуском.</p>',
            ],
        ],

    ],

];
