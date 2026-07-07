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
    'owner_cannot_leave' => 'Власник компанії не може залишити власну компанію.',
    'super_admin_cannot_own' => 'Адміністратори платформи не можуть створювати компанію або володіти нею.',

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
