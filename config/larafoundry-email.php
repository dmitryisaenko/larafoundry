<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry email templates (phase 5.1)
|--------------------------------------------------------------------------
| Registry of editable email templates. The SINGLE source of truth (like the
| larafoundry.settings registry): ONLY the templates registered here are editable
| (fail-closed) — the super-admin cannot create an arbitrary `code`, and an email
| is sent only if there is a Notification with that `code`.
|
| Data flow:
|   the registry (this file) — DEFAULTS: subject / body_html / body_text per locale
|                              + the `variables` placeholder whitelist.
|   larafoundry_email_templates (DB) — ONLY the super-admin's overrides.
|   No DB row → the email renders from the registry default (graceful, D-5.1-8).
|
| Adding a new email = +1 entry here (code + variables + default text) and +1
| Notification class with the same `code`. The UI editor picks it up automatically
| (it shows the union registry ∪ DB).
|
| Rendering is strict `{{variable}}` str_replace (NOT Blade, see TemplateRenderer
| + the test guard). body_html is sanitised by the email-friendly HTML purifier on
| write (HtmlSanitizer, ezyang/htmlpurifier). The variables used MUST be listed in
| the template's `variables` — otherwise the save is rejected (STRICT, D-5.1-11).
|
| The host's domain emails (employee_, payment_, company_ …) are NOT shipped by
| the core — the host adds them by publishing this config.
*/

return [

    /*
    | Sending a test email from the editor. throttle — "attempts,minutes" for the
    | route (protection against spamming other people's addresses — the donor lacked it).
    */
    'test_email' => [
        'throttle' => '5,1',
    ],

    /*
    | The template registry. Key = `code` (matches the lookup in the Notification).
    |   variables  — whitelist of {{...}} placeholders allowed in this template.
    |   subject    — the subject per locale.
    |   body_html  — the HTML body per locale (sanitised on write).
    |   body_text  — the plain-text body per locale (fallback for text clients).
    */
    'templates' => [

        // Welcome message after registration.
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

        // Password reset.
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

        // Email verification.
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

        // Company invitation.
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
