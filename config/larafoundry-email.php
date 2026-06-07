<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry email templates (phase 5.1)
|--------------------------------------------------------------------------
| Реестр редактируемых шаблонов писем. ЕДИНЫЙ источник истины (как реестр
| larafoundry.settings): editable ТОЛЬКО зарегистрированные здесь шаблоны
| (fail-closed) — super-admin не может завести произвольный `code`, а письмо
| отправляется лишь если есть Notification с этим `code`.
|
| Поток данных:
|   реестр (этот файл)  — ДЕФОЛТЫ: subject / body_html / body_text по локалям
|                         + whitelist переменных `variables`.
|   larafoundry_email_templates (БД) — ТОЛЬКО переопределения super-admin'а.
|   Нет БД-строки → письмо рендерится из дефолта реестра (graceful, D-5.1-8).
|
| Добавить новое письмо = +1 запись здесь (code + variables + дефолтный текст)
| и +1 Notification-класс с тем же `code`. UI-редактор подхватит его
| автоматически (показывает объединение реестр ∪ БД).
|
| Рендер — строгий `{{переменная}}` str_replace (НЕ Blade, см. TemplateRenderer
| + тест-страж). body_html чистится email-friendly HTML-purifier'ом на записи
| (HtmlSanitizer, ezyang/htmlpurifier). Доступные переменные ОБЯЗАНЫ входить в
| `variables` шаблона — иначе сохранение отклоняется (STRICT, D-5.1-11).
|
| Доменные письма host (employee_, payment_, company_ …) ядро НЕ закладывает —
| host добавляет их, публикуя этот конфиг.
*/

return [

    /*
    | Отправка тест-письма из редактора. throttle — «попыток,минут» для роута
    | (защита от рассылки спама на чужие адреса — донор её не имел).
    */
    'test_email' => [
        'throttle' => '5,1',
    ],

    /*
    | Реестр шаблонов. Ключ = `code` (совпадает с lookup в Notification).
    |   variables  — whitelist плейсхолдеров {{...}}, разрешённых в этом шаблоне.
    |   subject    — тема по локалям.
    |   body_html  — HTML-тело по локалям (чистится санитайзером на записи).
    |   body_text  — plain-text тело по локалям (fallback для текстовых клиентов).
    */
    'templates' => [

        // Приветствие после регистрации.
        'welcome_email' => [
            'variables' => ['name', 'app_name', 'login_url', 'support_url'],
            'subject' => [
                'en' => 'Welcome to {{app_name}}',
                'uk' => 'Ласкаво просимо до {{app_name}}',
            ],
            'body_html' => [
                'en' => '<p>Hi {{name}},</p><p>Welcome to {{app_name}}. Your account is ready.</p><p><a href="{{login_url}}">Sign in</a> to get started. If you need help, contact us at <a href="{{support_url}}">support</a>.</p>',
                'uk' => '<p>Привіт, {{name}}!</p><p>Ласкаво просимо до {{app_name}}. Ваш обліковий запис готовий.</p><p><a href="{{login_url}}">Увійдіть</a>, щоб почати. Якщо потрібна допомога, напишіть у <a href="{{support_url}}">підтримку</a>.</p>',
            ],
            'body_text' => [
                'en' => "Hi {{name}},\n\nWelcome to {{app_name}}. Your account is ready.\n\nSign in: {{login_url}}\nSupport: {{support_url}}",
                'uk' => "Привіт, {{name}}!\n\nЛаскаво просимо до {{app_name}}. Ваш обліковий запис готовий.\n\nУвійти: {{login_url}}\nПідтримка: {{support_url}}",
            ],
        ],

        // Сброс пароля.
        'password_reset' => [
            'variables' => ['name', 'reset_url'],
            'subject' => [
                'en' => 'Reset your password',
                'uk' => 'Скидання пароля',
            ],
            'body_html' => [
                'en' => '<p>Hi {{name}},</p><p>We received a request to reset your password.</p><p><a href="{{reset_url}}">Reset password</a></p><p>If you did not request this, you can safely ignore this email.</p>',
                'uk' => '<p>Привіт, {{name}}!</p><p>Ми отримали запит на скидання вашого пароля.</p><p><a href="{{reset_url}}">Скинути пароль</a></p><p>Якщо ви цього не робили, просто проігноруйте цей лист.</p>',
            ],
            'body_text' => [
                'en' => "Hi {{name}},\n\nWe received a request to reset your password.\n\nReset password: {{reset_url}}\n\nIf you did not request this, you can safely ignore this email.",
                'uk' => "Привіт, {{name}}!\n\nМи отримали запит на скидання вашого пароля.\n\nСкинути пароль: {{reset_url}}\n\nЯкщо ви цього не робили, просто проігноруйте цей лист.",
            ],
        ],

        // Подтверждение email.
        'email_verification' => [
            'variables' => ['name', 'verification_url'],
            'subject' => [
                'en' => 'Verify your email address',
                'uk' => 'Підтвердьте свою електронну пошту',
            ],
            'body_html' => [
                'en' => '<p>Hi {{name}},</p><p>Please confirm your email address to finish setting up your account.</p><p><a href="{{verification_url}}">Verify email</a></p>',
                'uk' => '<p>Привіт, {{name}}!</p><p>Будь ласка, підтвердьте свою електронну адресу, щоб завершити налаштування облікового запису.</p><p><a href="{{verification_url}}">Підтвердити пошту</a></p>',
            ],
            'body_text' => [
                'en' => "Hi {{name}},\n\nPlease confirm your email address to finish setting up your account.\n\nVerify email: {{verification_url}}",
                'uk' => "Привіт, {{name}}!\n\nБудь ласка, підтвердьте свою електронну адресу, щоб завершити налаштування облікового запису.\n\nПідтвердити пошту: {{verification_url}}",
            ],
        ],

        // Приглашение в компанию.
        'company_invitation' => [
            'variables' => ['company_name', 'inviter_name', 'accept_url', 'expires_at'],
            'subject' => [
                'en' => 'You have been invited to join {{company_name}}',
                'uk' => 'Вас запросили приєднатися до {{company_name}}',
            ],
            'body_html' => [
                'en' => '<p>Hi,</p><p>{{inviter_name}} has invited you to join <strong>{{company_name}}</strong>.</p><p><a href="{{accept_url}}">Accept invitation</a></p><p>This invitation expires on {{expires_at}}.</p>',
                'uk' => '<p>Вітаємо!</p><p>{{inviter_name}} запросив(-ла) вас приєднатися до <strong>{{company_name}}</strong>.</p><p><a href="{{accept_url}}">Прийняти запрошення</a></p><p>Запрошення дійсне до {{expires_at}}.</p>',
            ],
            'body_text' => [
                'en' => "Hi,\n\n{{inviter_name}} has invited you to join {{company_name}}.\n\nAccept invitation: {{accept_url}}\n\nThis invitation expires on {{expires_at}}.",
                'uk' => "Вітаємо!\n\n{{inviter_name}} запросив(-ла) вас приєднатися до {{company_name}}.\n\nПрийняти запрошення: {{accept_url}}\n\nЗапрошення дійсне до {{expires_at}}.",
            ],
        ],

    ],

];
