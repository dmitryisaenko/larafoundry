<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry Billing — backend translations (larafoundry:: namespace)
|--------------------------------------------------------------------------
| Ukrainian counterpart of lang/en/billing.php. See that file for the
| frontend-vs-backend translation split.
*/

return [

    'plan' => [
        'label' => 'Тариф',
    ],

    'promo' => [
        'invalid' => 'Цей промокод недійсний або термін його дії минув.',
    ],

    'entitlement' => [
        'required' => 'Ваш поточний тариф не включає цю можливість.',
    ],

    'payment' => [
        'success' => [
            'subject' => 'Платіж отримано',
            'greeting' => 'Платіж отримано',
            'line' => 'Ми отримали ваш платіж :amount за :company.',
            'thanks' => 'Дякуємо.',
        ],
        'failed' => [
            'subject' => 'Платіж не виконано',
            'greeting' => 'Платіж не виконано',
            'line' => 'Ваш платіж :amount за :company не вдалося обробити.',
            'help' => 'Будь ласка, перевірте спосіб оплати та спробуйте ще раз.',
        ],
        'no_company' => 'Немає активної компанії.',
        'not_connected' => 'Оплата не підключена. Будь ласка, зверніться до підтримки.',
        'checkout_failed' => 'Не вдалося розпочати оплату. Спробуйте ще раз.',
    ],

    'trial' => [
        'unavailable' => 'Безкоштовний місяць недоступний для цієї компанії.',
        'activated' => 'Ваш безкоштовний місяць активовано.',
    ],

    'subscription' => [
        'expiring' => [
            'subject' => 'Термін дії вашої підписки незабаром завершиться',
            'greeting' => 'Підписка незабаром завершиться',
            'line' => 'Термін дії підписки для :company завершиться через :days днів.',
            'action' => 'Будь ласка, поновіть підписку, щоб зберегти доступ до всіх можливостей.',
        ],
    ],

    // Super-admin console flash messages (B7b).
    'admin' => [
        'promo' => [
            'created' => 'Промокод створено.',
            'updated' => 'Промокод оновлено.',
            'activated' => 'Промокод активовано.',
            'deactivated' => 'Промокод деактивовано.',
        ],
        // Affiliate console flash (Ф4.4).
        'affiliate' => [
            'created' => 'Партнера створено.',
            'updated' => 'Партнера оновлено.',
        ],
        'payout' => [
            'approved' => 'Підтверджено комісій: :count.',
            'paid' => 'Позначено сплаченими комісій: :count.',
            'voided' => 'Скасовано комісій: :count.',
        ],
    ],

];
