<?php

declare(strict_types=1);
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy (phase 1.2)
    |--------------------------------------------------------------------------
    | mode:
    |   'teams'    — мультикомпании, тенант = Company (как kohana.io). Включает
    |                company-creation визард, приглашения, переключение компании.
    |   'personal' — тенант = сам User (без компаний). Company-флоу не
    |                регистрируется; BelongsToTenant фильтрует по user_id.
    |
    | company_model — модель компании. Host наследует базовую и подставляет свою
    |                 (`App\Models\Company extends ...\Tenancy\Models\Company`).
    | foreign_key   — имя FK-колонки тенанта на доменных моделях. В teams это
    |                 'company_id'; BelongsToTenant читает его отсюда. В personal
    |                 трейт фильтрует по user_id независимо от этого значения.
    | invitation_expiry_days — срок жизни приглашения сотрудника.
    | routes_without_active_tenant — имена роутов (fnmatch), доступные company-
    |                 пользователю БЕЗ выбранной активной компании (напр. запрос
    |                 на удаление себя из компании). Используется EnsureActiveTenant.
    */
    'tenancy' => [
        'mode' => env('LARAFOUNDRY_TENANCY_MODE', 'teams'),
        'company_model' => Company::class,
        'foreign_key' => 'company_id',
        'invitation_expiry_days' => 7,
        'routes_without_active_tenant' => [
            'tenancy.employees.request-removal',
            'tenancy.employees.cancel-removal',
        ],

        // Where to land after a company is set up / an invite is accepted. The
        // dashboard is host territory, so the core only knows a route name (or
        // path) to redirect to; default '/' keeps the package self-contained.
        'home_route' => env('LARAFOUNDRY_TENANCY_HOME', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing (шов, phase 3.1)
    |--------------------------------------------------------------------------
    | В бесплатном ядре живёт только ШОВ: контракты + драйвер-менеджер + null-
    | реализации. Реальные платежи (Cashier Stripe/Paddle, promo, trial-UI,
    | portal, метрики) — в платном аддоне `dmitryisaenko/larafoundry-billing`,
    | который встаёт в эти контракты.
    |
    | enabled — главный рубильник доступа. false (по умолчанию) = бесплатный
    |           self-host без ограничений: Company::hasAccess() всегда true,
    |           ворота не блокируют. true (обычно вместе с аддоном) = hasAccess()
    |           читает реальное состояние подписки из billing-колонок companies
    |           (fail-closed: нет валидного trial/подписки → нет доступа).
    |
    | gateway.default — имя драйвера платёжного шлюза по умолчанию. В ядре
    |           зарегистрирован только 'null' (ничего не принимает). Аддон/host
    |           регистрируют 'stripe'/'paddle'/локальный PSP через
    |           PaymentGatewayManager::extend() и указывают его здесь.
    |
    | region.default_currency — валюта по умолчанию (ISO 4217), когда нет
    |           маппинга страна→валюта. Per-country цены/валюты/выбор шлюза —
    |           зона аддона/host через свою реализацию RegionContext.
    */
    'billing' => [
        'enabled' => env('LARAFOUNDRY_BILLING_ENABLED', false),

        'gateway' => [
            'default' => env('LARAFOUNDRY_BILLING_GATEWAY', 'null'),
        ],

        'region' => [
            'default_currency' => env('LARAFOUNDRY_BILLING_CURRENCY', 'USD'),
        ],
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
        'available' => ['en', 'uk'],
        'default' => env('LARAFOUNDRY_LOCALE', 'en'),
        'cookie' => 'locale',
        'locales' => [
            'en' => ['native' => 'English', 'flag' => '🇬🇧'],
            'uk' => ['native' => 'Українська', 'flag' => '🇺🇦'],
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
    | Operator console (phase 2.3)
    |--------------------------------------------------------------------------
    | Settings for the super-admin console (user management, impersonation,
    | company management).
    |
    | users_per_page     — page size for the admin user list.
    | companies_per_page — page size for the admin company list (phase 3.3).
    | subscription_expiring_within_days — a still-active subscription whose end
    |   date falls inside this many days is classified as "expiring" in the
    |   company list (status badge + filter). Display-only; access is still
    |   granted until the end date passes (Company::hasAccess()).
    */
    'admin' => [
        'users_per_page' => 21,
        'companies_per_page' => 21,
        'subscription_expiring_within_days' => 7,
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
