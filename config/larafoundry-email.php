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

        // An invited person accepted and joined -> confirm to the joining member
        // (matrix 1.1 / 2.1, UserEmail).
        'employee_joined_confirmation' => [
            'variables' => ['app_name', 'member_name', 'company_name'],
            'subject' => [
                'en' => 'You have joined {{company_name}}',
                'uk' => 'Ви приєдналися до {{company_name}}',
            ],
            'body_html' => [
                'en' => '<p>Hi {{member_name}},</p><p>You have joined <strong>{{company_name}}</strong>.</p><p>You can now sign in to {{app_name}} and start working with your team.</p>',
                'uk' => '<p>Привіт, {{member_name}}!</p><p>Ви приєдналися до <strong>{{company_name}}</strong>.</p><p>Тепер ви можете увійти на {{app_name}} та почати роботу зі своєю командою.</p>',
            ],
            'body_text' => [
                'en' => "Hi {{member_name}},\n\nYou have joined {{company_name}}.\n\nYou can now sign in to {{app_name}} and start working with your team.",
                'uk' => "Привіт, {{member_name}}!\n\nВи приєдналися до {{company_name}}.\n\nТепер ви можете увійти на {{app_name}} та почати роботу зі своєю командою.",
            ],
        ],

        // An invited person accepted and joined -> tell the owner (matrix 1.1 / 2.1).
        'invitation_accepted_owner' => [
            'variables' => ['app_name', 'owner_name', 'member_name', 'company_name'],
            'subject' => [
                'en' => '{{member_name}} joined {{company_name}}',
                'uk' => '{{member_name}} приєднався(-лася) до {{company_name}}',
            ],
            'body_html' => [
                'en' => '<p>Hi {{owner_name}},</p><p>{{member_name}} has accepted your invitation and joined <strong>{{company_name}}</strong>.</p><p>They now have access on {{app_name}}.</p>',
                'uk' => '<p>Привіт, {{owner_name}}!</p><p>{{member_name}} прийняв(-ла) ваше запрошення та приєднався(-лася) до <strong>{{company_name}}</strong>.</p><p>Тепер у нього(неї) є доступ на {{app_name}}.</p>',
            ],
            'body_text' => [
                'en' => "Hi {{owner_name}},\n\n{{member_name}} has accepted your invitation and joined {{company_name}}.\n\nThey now have access on {{app_name}}.",
                'uk' => "Привіт, {{owner_name}}!\n\n{{member_name}} прийняв(-ла) ваше запрошення та приєднався(-лася) до {{company_name}}.\n\nТепер у нього(неї) є доступ на {{app_name}}.",
            ],
        ],

        // An invited person declined -> tell the owner (matrix 2.2).
        'invitation_rejected_owner' => [
            'variables' => ['app_name', 'owner_name', 'invited_email', 'company_name'],
            'subject' => [
                'en' => 'Invitation to {{company_name}} was declined',
                'uk' => 'Запрошення до {{company_name}} відхилено',
            ],
            'body_html' => [
                'en' => '<p>Hi {{owner_name}},</p><p>The invitation you sent to {{invited_email}} to join <strong>{{company_name}}</strong> was declined.</p><p>You can invite someone else at any time on {{app_name}}.</p>',
                'uk' => '<p>Привіт, {{owner_name}}!</p><p>Запрошення, яке ви надіслали на {{invited_email}} для приєднання до <strong>{{company_name}}</strong>, було відхилено.</p><p>Ви можете запросити когось іншого будь-коли на {{app_name}}.</p>',
            ],
            'body_text' => [
                'en' => "Hi {{owner_name}},\n\nThe invitation you sent to {{invited_email}} to join {{company_name}} was declined.\n\nYou can invite someone else at any time on {{app_name}}.",
                'uk' => "Привіт, {{owner_name}}!\n\nЗапрошення, яке ви надіслали на {{invited_email}} для приєднання до {{company_name}}, було відхилено.\n\nВи можете запросити когось іншого будь-коли на {{app_name}}.",
            ],
        ],

        // A member was removed after their own removal request was approved (matrix 4.1).
        'employee_removed_notification' => [
            'variables' => ['app_name', 'member_name', 'company_name'],
            'subject' => [
                'en' => 'You have been removed from {{company_name}}',
                'uk' => 'Вас видалено з {{company_name}}',
            ],
            'body_html' => [
                'en' => '<p>Hi {{member_name}},</p><p>Your request to leave <strong>{{company_name}}</strong> has been approved and you have been removed.</p><p>You no longer have access to this company on {{app_name}}.</p>',
                'uk' => '<p>Привіт, {{member_name}}!</p><p>Ваш запит на вихід із <strong>{{company_name}}</strong> схвалено, і вас видалено.</p><p>Ви більше не маєте доступу до цієї компанії на {{app_name}}.</p>',
            ],
            'body_text' => [
                'en' => "Hi {{member_name}},\n\nYour request to leave {{company_name}} has been approved and you have been removed.\n\nYou no longer have access to this company on {{app_name}}.",
                'uk' => "Привіт, {{member_name}}!\n\nВаш запит на вихід із {{company_name}} схвалено, і вас видалено.\n\nВи більше не маєте доступу до цієї компанії на {{app_name}}.",
            ],
        ],

        // A user created a company -> confirm to the owner (matrix 8).
        'company_created' => [
            'variables' => ['app_name', 'owner_name', 'company_name'],
            'subject' => [
                'en' => '{{company_name}} is ready',
                'uk' => '{{company_name}} готова',
            ],
            'body_html' => [
                'en' => '<p>Hi {{owner_name}},</p><p>Your company <strong>{{company_name}}</strong> has been created on {{app_name}}.</p><p>You can now invite your team and start working.</p>',
                'uk' => '<p>Привіт, {{owner_name}}!</p><p>Вашу компанію <strong>{{company_name}}</strong> створено на {{app_name}}.</p><p>Тепер ви можете запросити свою команду та почати роботу.</p>',
            ],
            'body_text' => [
                'en' => "Hi {{owner_name}},\n\nYour company {{company_name}} has been created on {{app_name}}.\n\nYou can now invite your team and start working.",
                'uk' => "Привіт, {{owner_name}}!\n\nВашу компанію {{company_name}} створено на {{app_name}}.\n\nТепер ви можете запросити свою команду та почати роботу.",
            ],
        ],

        // An owner archived (soft-deleted, recoverable) their company (matrix 9).
        'company_deleted_confirmation' => [
            'variables' => ['app_name', 'owner_name', 'company_name'],
            'subject' => [
                'en' => '{{company_name}} has been archived',
                'uk' => '{{company_name}} архівовано',
            ],
            'body_html' => [
                'en' => '<p>Hi {{owner_name}},</p><p>Your company <strong>{{company_name}}</strong> has been archived on {{app_name}}.</p><p>Its data is kept and you can restore it at any time.</p>',
                'uk' => '<p>Привіт, {{owner_name}}!</p><p>Вашу компанію <strong>{{company_name}}</strong> архівовано на {{app_name}}.</p><p>Її дані збережено, і ви можете відновити її будь-коли.</p>',
            ],
            'body_text' => [
                'en' => "Hi {{owner_name}},\n\nYour company {{company_name}} has been archived on {{app_name}}.\n\nIts data is kept and you can restore it at any time.",
                'uk' => "Привіт, {{owner_name}}!\n\nВашу компанію {{company_name}} архівовано на {{app_name}}.\n\nЇї дані збережено, і ви можете відновити її будь-коли.",
            ],
        ],

    ],

];
