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
    | super_admin       — платформенный супер-админ (phase 1.4):
    |   email           — эксклюзивный email оператора. Когда задан, ТОЛЬКО он
    |                     (вместе с флагом is_admin) получает admin-статус в
    |                     VisitorStatus, И этот email зарезервирован: не может
    |                     зарегистрироваться обычным юзером и не может владеть
    |                     компанией (operator-личность отделена от tenant-личностей).
    |                     Пусто = fallback на auth.failed_login.admin_email
    |                     (обратная совместимость), затем «флага достаточно».
    |   console_route   — имя роута, в который изолируется супер-админ
    |                     (RedirectSuperAdminToConsole редиректит его сюда из
    |                     tenant-зон).
    |   allowed_routes  — имена роутов (fnmatch), доступные супер-админу ВНЕ
    |                     редиректа в консоль (сама консоль, OTP-gate, PIN, logout).
    | email_verification — настройки EnsureEmailIsVerified middleware:
    |   redirect_route  — куда отправлять непроверенного пользователя.
    |   except_routes   — имена роутов (fnmatch-паттерны), доступные без проверки.
    |   except_prefixes — префиксы путей, доступные без проверки.
    */
    'security' => [
        'admin_ips' => env('LARAFOUNDRY_ADMIN_IPS', ''),
        'super_admin' => [
            'email' => env('LARAFOUNDRY_SUPER_ADMIN_EMAIL'),
            'console_route' => 'admin.dashboard.index',
            'allowed_routes' => [
                'admin.*',
                'pin.*',
                'logout',
                'password.confirm*',
            ],

            // OTP step-up gate (phase 1.4): the operator console requires a
            // fresh two-factor code once per session (EnsureAdminOtpVerified),
            // on top of any 2FA challenge at login — so OAuth logins (which skip
            // Fortify's login challenge) still prove OTP before reaching /admin.
            //
            // require_otp        — master switch. true = enforce the gate.
            // two_factor_setup_route — route NAME of the host's 2FA-enrolment
            //   screen. A super-admin without confirmed 2FA is sent here. Null
            //   (no host route) means the gate denies with 403 instead (fail
            //   closed: no operator access without 2FA configured).
            'require_otp' => env('LARAFOUNDRY_ADMIN_REQUIRE_OTP', true),
            'two_factor_setup_route' => env('LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE'),
        ],
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
    | Session PIN-lock (phase 1.4)
    |--------------------------------------------------------------------------
    | Telegram-style быстрый повторный вход: после простоя (или ручной блокировки)
    | активная веб-сессия требует короткий PIN вместо полного релогина. PIN —
    | опционален, любой пользователь включает его в профиле; хранится хешем.
    |
    | enabled         — главный рубильник. false = PIN-механика не применяется
    |                   (CheckPinLock middleware = no-op), даже если PIN задан.
    | length          — длина PIN (цифр). Донорский дефолт — 4.
    | idle_timeout    — секунд бездействия, после которых сессия авто-блокируется
    |                   (только та сессия, что простаивала).
    | max_attempts    — неверных вводов подряд до временной блокировки ввода.
    | lockout_minutes — на сколько минут запирается ВВОД PIN после превышения
    |                   max_attempts (анти-brute-force; донор этого не имел).
    */
    'pin' => [
        'enabled' => env('LARAFOUNDRY_PIN_ENABLED', true),
        'length' => 4,
        'idle_timeout' => 1800,
        'max_attempts' => 5,
        'lockout_minutes' => 15,
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
    | presentation         — how the guest auth screens (login/register/forgot/
    |                        reset) are surfaced: `page` (a full page, the
    |                        default) or `modal` (an overlay over the host's
    |                        content). Read by the AuthScreen frontend wrapper via
    |                        the shared `auth_presentation` prop; an unknown value
    |                        falls back to `page` so the surface never breaks.
    */
    'auth' => [
        'password_min_length' => 8,

        'presentation' => env('LARAFOUNDRY_AUTH_PRESENTATION', 'page'), // page | modal

        // QR cross-device login (phase 1.4 part 2). The web side shows a QR an
        // already-authenticated device scans to approve a login.
        //  enabled              — master switch for the QR routes + Login QR tab.
        //  ttl_minutes          — how long a freshly generated code stays valid.
        //  absolute_ttl_minutes — hard cap measured from creation, so a code that
        //                         keeps sliding forward still dies (donor hole #4).
        //  size                 — rendered QR side length in px.
        //  poll_interval_ms     — how often the web side polls for approval.
        'qr' => [
            'enabled' => env('LARAFOUNDRY_QR_ENABLED', true),
            'ttl_minutes' => 5,
            'absolute_ttl_minutes' => 15,
            'size' => 400,
            'poll_interval_ms' => 2000,
        ],

        // Social sign-in (Socialite). The controller is provider-agnostic: any
        // slug listed here is accepted as long as a Socialite driver is
        // registered and `enabled` is true. The frontend renders one button per
        // listed provider (config-driven, shared as the `auth_oauth` Inertia
        // prop) — adding a provider here is all the core needs.
        //
        //  enabled   — master switch for the OAuth routes + the Login buttons.
        //  providers — the blessed allow-list. The default set is the providers
        //              Socialite ships built-in (no extra package): google,
        //              facebook and twitter (OAuth 1.0a). A button only actually
        //              works once the host supplies that provider's credentials
        //              in `config/services.php` + `.env`; the core ships none.
        //              Community providers (apple, microsoft, linkedin, twitter
        //              OAuth 2.0) need a `socialiteproviders/*` package the host
        //              installs and registers — then just add the slug here.
        //  link_existing — when true, an OAuth login whose email matches an
        //              existing local account links to it instead of being
        //              refused (the anti-takeover default is false).
        'oauth' => [
            'enabled' => env('LARAFOUNDRY_OAUTH_ENABLED', false),
            'providers' => ['google', 'facebook', 'twitter'],
            'link_existing' => false,
            'redirect_after_login' => '/',
        ],

        // Security alert when the super-admin account is the target of a failed
        // auth step. One unified signal — AdminAccessAttemptFailed — is raised
        // for a bad password/lockout, a wrong operator-console OTP, or a wrong
        // session PIN. The core's neutral default delivery is mail; a host adds
        // extra channels (e.g. Telegram) by listening to that same event and
        // appending its channel name to `channels` below — no core change.
        //
        // Three independent axes, all checked via AdminAccessAlertPolicy so the
        // "failure type x channel" matrix lives in one place for every channel:
        'failed_login' => [
            // Master on/off switch for all admin-access alerts.
            'notify_admin' => env('LARAFOUNDRY_NOTIFY_LOGIN_FAIL', false),

            // The super-admin email an alert protects. Falls back to
            // security.super_admin.email via VisitorStatus when left null.
            'admin_email' => env('LARAFOUNDRY_ADMIN_EMAIL'),

            // Which failure TYPES raise an alert. For "OTP only" use ['admin_otp'].
            'alert_on' => ['password', 'lockout', 'admin_otp', 'pin'],

            // Which CHANNELS deliver. The core knows 'mail'; a host listening on
            // AdminAccessAttemptFailed appends its own (e.g. 'telegram') and
            // checks AdminAccessAlertPolicy::shouldAlert($step, 'telegram').
            'channels' => ['mail'],

            // Optional: a host may swap the core mail notification for its own
            // subclass of AdminLoginAttemptNotification (FQCN). Null = core default.
            'notification' => null,
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
    | active_within_days — a user with tracked activity inside this many days is
    |   counted as "active" in the dashboard user widget (phase 3.4).
    | dashboard_activity_limit — how many recent admin-log events the dashboard
    |   activity widget shows (phase 3.4). Clamped to a sane ceiling in code.
    */
    'admin' => [
        'users_per_page' => 21,
        'companies_per_page' => 21,
        'subscription_expiring_within_days' => 7,
        'active_within_days' => 30,
        'dashboard_activity_limit' => 10,
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

    /*
    |--------------------------------------------------------------------------
    | Profile (phase 5.1)
    |--------------------------------------------------------------------------
    | ui_settings — БЕЛЫЙ СПИСОК пользовательских UI-предпочтений, хранимых в
    | колонке users.ui_settings (JSON). В донорe в эту колонку писался ЛЮБОЙ
    | ключ/значение (recon finding #2) — здесь fail-closed: пишутся и читаются
    | ТОЛЬКО зарегистрированные ключи, каждый со своим типом (значение кастуется,
    | не доверяется) и опциональным enum `in`. Host добавляет свои предпочтения,
    | публикуя этот конфиг; доменные настройки (per-company/app) — это уже модуль
    | Settings (под-фаза B), не сюда.
    |
    |   type    — 'boolean' | 'integer' | 'float' | 'string' (каст значения).
    |   default — значение по умолчанию, когда ключ ещё не сохранён.
    |   in      — (опц.) допустимый набор значений (enum).
    */
    'profile' => [
        'ui_settings' => [
            'theme' => [
                'type' => 'string',
                'default' => 'system',
                'in' => ['light', 'dark', 'system'],
            ],
            'sidebar_collapsed' => [
                'type' => 'boolean',
                'default' => false,
            ],
            'table_density' => [
                'type' => 'string',
                'default' => 'comfortable',
                'in' => ['comfortable', 'compact'],
            ],
        ],

        // Personal-data export (phase 5.3): how often a user may pull a full JSON
        // dump of their own data. Format "<attempts>,<minutes>" — default 3/day.
        'data_export' => [
            'throttle' => '3,1440',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings (phase 5.1)
    |--------------------------------------------------------------------------
    | Реестр generic key-value настроек (таблица larafoundry_settings). ЕДИНЫЙ
    | источник истины: writable/readable ТОЛЬКО зарегистрированные ключи
    | (fail-closed) — произвольный key в store не попадёт. Каждый ключ:
    |
    |   scope      — app | company | user (где живёт значение и кто им владеет:
    |                app=супер-админ, company=активная компания[RBAC
    |                company.settings.*], user=сам пользователь).
    |   type       — boolean | integer | float | string | array (каст значения).
    |   default    — значение по умолчанию, когда ничего не сохранено.
    |   validation — Laravel-правило валидации значения при записи.
    |   in         — (опц.) enum допустимых значений (для select в UI).
    |   public     — (опц., только app) отдавать ли значение во фронт (host
    |                шарит через Inertia::share).
    |   form       — (опц., default true) показывать ли ключ в self-service форме.
    |                false = ключ хранится в store, но пишется не формой, а другим
    |                флоу (напр. consent-флаги — пишет визард согласий Ф5.3).
    |
    | Host добавляет свои настройки, публикуя этот конфиг. Доменных настроек ядро
    | не закладывает — только инфраструктурные + швы под Ф5.3 (consent).
    */
    'settings' => [

        // App scope — платформенные, правит только супер-админ.
        'support_email' => [
            'scope' => 'app',
            'label' => 'Support email',
            'type' => 'string',
            'default' => null,
            'validation' => ['nullable', 'email', 'max:255'],
            'public' => true,
        ],
        'signups_enabled' => [
            'scope' => 'app',
            'label' => 'Allow new sign-ups',
            'type' => 'boolean',
            'default' => true,
            'validation' => ['boolean'],
            'public' => true,
        ],

        // Company scope — правит владелец/роль с company.settings.update.
        'timezone' => [
            'scope' => 'company',
            'label' => 'Time zone',
            'type' => 'string',
            'default' => 'UTC',
            'validation' => ['string', 'timezone'],
        ],

        // User scope — правит сам пользователь.
        'email_notifications' => [
            'scope' => 'user',
            'label' => 'Email notifications',
            'type' => 'boolean',
            'default' => true,
            'validation' => ['boolean'],
        ],

        // User scope — швы согласий под Ф5.3 (Legal/GDPR). Зарегистрированы,
        // чтобы store мог их держать, но НЕ в self-service форме (form=false):
        // их пишет флоу согласий Ф5.3, не страница настроек.
        'cookie_consent' => [
            'scope' => 'user',
            'type' => 'boolean',
            'default' => false,
            'validation' => ['boolean'],
            'form' => false,
        ],
        'terms_accepted_version' => [
            'scope' => 'user',
            'type' => 'string',
            'default' => null,
            'validation' => ['nullable', 'string', 'max:50'],
            'form' => false,
        ],
        'terms_accepted_at' => [
            'scope' => 'user',
            'type' => 'string',
            'default' => null,
            'validation' => ['nullable', 'date'],
            'form' => false,
        ],
    ],

];
