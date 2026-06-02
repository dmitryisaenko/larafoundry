<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    | mode:
    |   'teams'    — мультикомпании, тенант = Company (как kohana.io)
    |   'personal' — тенант = сам User (без компаний)
    | tenant_model — конкретная модель тенанта в host-приложении.
    */
    'tenancy' => [
        'mode' => env('LARAFOUNDRY_TENANCY_MODE', 'teams'),
        'tenant_model' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing (шов)
    |--------------------------------------------------------------------------
    | Реализация — в платном аддоне `dmitryisaenko/larafoundry-billing`.
    | В бесплатном ядре остаётся только контракт; hasAccess() = true.
    */
    'billing' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    | ОДИН источник истины: locale = код ISO 639-1 ('en', 'uk', 'de'). Этот код
    | используется ВЕЗДЕ — в URL, cookie, БД, переводах. Никаких 'ua', 'English'
    | в роли ключа, никаких параллельных списков. Всё остальное (название языка,
    | флаг) — это метаданные ОДНОЙ локали, а не отдельные массивы.
    |
    | available  — белый список доступных локалей (ISO 639-1). Единственный
    |              список, по которому валидируется ВСЁ. Невалидный код (напр.
    |              'ua') физически не применится — SetLocale его отбросит.
    | default    — fallback-локаль (обязана быть в available).
    | cookie     — имя cookie, где хранится выбор гостя.
    | locales    — метаданные на локаль (native-название, флаг) для UI-свитчера.
    | detect_map — ТОЛЬКО исключения для авто-определения по браузеру. Код,
    |              совпадающий с одной из available, берётся как есть и его сюда
    |              писать НЕ нужно. Здесь живут лишь «не-тождественные» случаи
    |              (напр. русскоязычный браузер → украинский интерфейс).
    | geoip      — опциональный гео-резолвер по IP. По умолчанию OFF: синхронный
    |              внешний HTTP на каждый запрос — медленно, утечка IP третьей
    |              стороне, точка отказа. Host может включить и подставить свой
    |              класс (implements LocaleGeoResolver). Маппинг страна→локаль —
    |              забота этого резолвера, не ядра (страны = бизнес-сущность host).
    */
    'locale' => [
        'available' => ['en'],
        'default' => env('LARAFOUNDRY_LOCALE', 'en'),
        'cookie' => 'locale',
        'locales' => [
            'en' => ['native' => 'English', 'flag' => '🇬🇧'],
        ],
        'detect_map' => [
            // 'ru' => 'uk',
        ],
        'geoip' => [
            'enabled' => false,
            'resolver' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    | admin_ips         — список IP (CSV или массив), которым разрешён вход в
    |                     admin/auth-зону на проде. Пусто = ограничения нет.
    |                     Используется RestrictAuthByIp middleware.
    | email_verification — настройки EnsureEmailIsVerified middleware:
    |   redirect_route  — куда отправлять непроверенного пользователя.
    |   except_routes   — имена роутов (fnmatch-паттерны), доступные без проверки.
    |   except_prefixes — префиксы путей, доступные без проверки.
    */
    'security' => [
        'admin_ips' => env('LARAFOUNDRY_ADMIN_IPS', ''),
        'email_verification' => [
            'redirect_route' => 'verification.notice',
            'except_routes' => [
                'verification.*',
                'logout',
            ],
            'except_prefixes' => [
                'profile',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication (phase 1.1)
    |--------------------------------------------------------------------------
    | The core builds its auth flow on top of Laravel Fortify (login/register/
    | reset/verify/password-confirm + per-user TOTP two-factor). These options
    | tune the pieces the core adds AROUND Fortify; Fortify's own behaviour
    | lives in the host's published config/fortify.php.
    |
    | password_min_length — floor for new/reset passwords. The core also applies
    |                       Laravel's Password::defaults() rules on top.
    | oauth.enabled        — master switch for the Socialite redirect/callback.
    | oauth.providers      — providers exposed by the core's OAuth routes. Keys
    |                        and secrets live in config/services.* (host-owned).
    | oauth.link_existing  — when an OAuth identity resolves to an email that
    |                        already has a LOCAL account, whether to auto-link.
    |                        Default false: auto-linking on email alone is an
    |                        account-takeover vector (donor had this hole). When
    |                        false, the callback refuses and tells the user to
    |                        sign in locally first, then link from settings.
    | failed_login.notify  — email/log channel notified on admin login failures.
    | two_factor.confirm   — require the user to confirm TOTP enrolment with a
    |                        live code before it is active (recommended).
    */
    'auth' => [
        'password_min_length' => 8,

        'oauth' => [
            'enabled' => env('LARAFOUNDRY_OAUTH_ENABLED', false),
            'providers' => ['google', 'facebook', 'github'],
            'link_existing' => false,
            'redirect_after_login' => '/',
        ],

        'failed_login' => [
            'notify_admin' => env('LARAFOUNDRY_NOTIFY_LOGIN_FAIL', false),
            'admin_email' => env('LARAFOUNDRY_ADMIN_EMAIL'),
        ],

        'two_factor' => [
            'confirm' => true,
        ],

        // Route name the EnsureAccountIsActive middleware redirects blocked /
        // deleted users to. Null falls back to '/' with a flashed error.
        'blocked_redirect_route' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inertia shared props
    |--------------------------------------------------------------------------
    | Управляет core-частью Inertia::share (flash/locale/translations/…).
    | intended_url:
    |   except_routes — роуты (имена), которые НЕ должны сохраняться как
    |                   "intended url" (JSON-эндпоинты, polling и т.п.).
    */
    'inertia' => [
        'intended_url' => [
            'except_routes' => [],
        ],
    ],

];
