<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry tenancy strings (namespace: larafoundry::tenancy)
|--------------------------------------------------------------------------
| Ukrainian translation of the core tenancy strings, shipped out of the box.
| Hosts override via `vendor:publish --tag=larafoundry-lang`.
*/

return [

    'no_active_company' => 'Спершу оберіть або створіть компанію.',
    'company_blocked' => 'Цю компанію заблоковано. Зверніться до підтримки.',
    'company_archived' => 'Цю компанію заархівовано. Попросіть власника відновити її.',
    'company_archived_done' => 'Компанію заархівовано.',
    'company_unarchived' => 'Компанію відновлено.',
    'company_created' => 'Компанію створено.',
    'company_switched' => 'Тепер ви працюєте як :company.',
    'setup_complete' => 'Вашу компанію готово.',

    'invitation_sent' => 'Запрошення надіслано.',
    'invitation_revoked' => 'Запрошення відкликано.',
    'employee_created' => 'Співробітника створено.',
    'employee_updated' => 'Дані співробітника оновлено.',
    'employee_removed' => 'Співробітника видалено.',
    'removal_requested' => 'Ваш запит на видалення надіслано.',
    'removal_cancelled' => 'Ваш запит на видалення скасовано.',
    'removal_rejected' => 'Запит на видалення відхилено. Учасник залишається в компанії.',
    'owner_cannot_leave' => 'Власник компанії не може залишити власну компанію.',
    'super_admin_cannot_own' => 'Адміністратори платформи не можуть створювати компанію або володіти нею.',

    'notify_mail' => [
        'invitation_accepted_owner' => [
            'subject' => 'Хтось приєднався до :company',
            'line' => ':member прийняв(-ла) ваше запрошення та приєднався(-лася) до :company.',
        ],
        'invitation_rejected_owner' => [
            'subject' => 'Запрошення до :company відхилено',
            'line' => 'Запрошення, яке ви надіслали на :email для приєднання до :company, відхилено.',
        ],
        'employee_joined' => [
            'subject' => 'Ви приєдналися до :company',
            'line' => 'Ви приєдналися до :company. Тепер ви можете увійти та почати роботу зі своєю командою.',
        ],
        'employee_removed' => [
            'subject' => 'Вас видалено з :company',
            'line' => 'Ваш запит на вихід із :company схвалено, і вас видалено.',
        ],
        'company_created' => [
            'subject' => ':company готова',
            'line' => 'Вашу компанію :company створено.',
        ],
        'company_deleted' => [
            'subject' => ':company архівовано',
            'line' => 'Вашу компанію :company архівовано. Ви можете відновити її будь-коли.',
        ],
    ],

    'invitation' => [
        'subject' => 'Вас запросили приєднатися до :company',
        'intro' => 'Вас запросили приєднатися до :company. Натисніть кнопку нижче, щоб прийняти.',
        'action' => 'Прийняти запрошення',
        'outro' => 'Якщо ви не очікували цього запрошення, можете проігнорувати цей лист.',
        'invalid' => 'Це запрошення більше не дійсне.',
        'email_mismatch' => 'Це запрошення було надіслано на іншу електронну адресу.',
        'accepted' => 'Ви приєдналися до :company.',
        'rejected' => 'Запрошення відхилено.',
    ],

];
