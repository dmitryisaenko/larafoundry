<?php

declare(strict_types=1);

/*
 * Серверні рядки для центру сповіщень (фаза 4.1).
 *
 * Flash-повідомлення від ендпоінтів розсилок супер-адміна. Підписи інтерфейсу
 * живуть у фронтенд-словнику (lang/frontend/*.json) — тут лише серверні flash.
 */

return [
    'broadcast' => [
        'created' => 'Розсилку збережено як чернетку.',
        'updated' => 'Розсилку оновлено.',
        'queued' => 'Розсилка доставляється.',
        'deleted' => 'Розсилку видалено.',
        'not_draft' => 'Редагувати або надіслати можна лише чернетку розсилки.',
    ],

    /*
     * In-app формулювання для життєвого циклу власник-співробітник (фаза 2a).
     */
    'tenancy' => [
        'action_view_team' => 'Переглянути команду',
        'action_view_home' => 'Відкрити',

        'invited' => [
            'owner' => [
                'title' => 'Ви запросили :email',
                'body' => 'Запрошення приєднатися до :company надіслано на :email.',
            ],
            'user' => [
                'title' => 'Вас запросили до :company',
                'body' => ':inviter запросив(-ла) вас приєднатися до :company.',
            ],
        ],
        'accepted' => [
            'owner' => [
                'title' => ':member приєднався(-лася) до :company',
                'body' => ':member прийняв(-ла) ваше запрошення та приєднався(-лася) до :company.',
            ],
            'user' => [
                'title' => 'Ви приєдналися до :company',
                'body' => 'Тепер у вас є доступ до :company.',
            ],
        ],
        'rejected' => [
            'owner' => [
                'title' => 'Запрошення до :company відхилено',
                'body' => ':email відхилив(-ла) ваше запрошення приєднатися до :company.',
            ],
            'user' => [
                'title' => 'Запрошення відхилено',
                'body' => 'Ви відхилили запрошення приєднатися до :company.',
            ],
        ],
        'removed' => [
            'owner' => [
                'title' => ':member видалено з :company',
                'body' => ':member більше не має доступу до :company.',
            ],
            'user' => [
                'title' => 'Вас видалено з :company',
                'body' => 'Ви більше не маєте доступу до :company.',
            ],
        ],
        'removal_requested' => [
            'owner' => [
                'title' => ':member просить вийти з :company',
                'body' => ':member надіслав(-ла) запит на видалення з :company. Ви можете схвалити або відхилити його.',
            ],
            'user' => [
                'title' => 'Запит на видалення надіслано',
                'body' => 'Ваш запит на вихід із :company надіслано власнику.',
            ],
        ],
        'removal_cancelled' => [
            'owner' => [
                'title' => ':member відкликав(-ла) запит на видалення',
                'body' => ':member скасував(-ла) запит на вихід із :company.',
            ],
            'user' => [
                'title' => 'Запит на видалення відкликано',
                'body' => 'Ви скасували запит на вихід із :company.',
            ],
        ],
        'removal_rejected' => [
            'owner' => [
                'title' => 'Ви відхилили запит на видалення',
                'body' => ':member залишається в :company.',
            ],
            'user' => [
                'title' => 'Ваш запит на видалення відхилено',
                'body' => 'Ви залишаєтеся учасником :company.',
            ],
        ],
        'invitation_withdrawn' => [
            'owner' => [
                'title' => 'Запрошення відкликано',
                'body' => 'Запрошення на :email для :company відкликано.',
            ],
        ],
        'invitation_resent' => [
            'owner' => [
                'title' => 'Запрошення надіслано повторно',
                'body' => 'Запрошення на :email для :company надіслано повторно.',
            ],
        ],
        'role_changed' => [
            'user' => [
                'title' => 'Ваш доступ у :company змінено',
                'body' => 'Власник оновив ваші ролі в :company.',
            ],
        ],
        'company_created' => [
            'owner' => [
                'title' => ':company готова',
                'body' => 'Вашу компанію :company створено.',
            ],
        ],
        'company_archived' => [
            'owner' => [
                'title' => ':company архівовано',
                'body' => 'Вашу компанію :company архівовано. Ви можете відновити її будь-коли.',
            ],
        ],
    ],
];
