<?php

declare(strict_types=1);

/*
 * The credits module (v0.46.0) - see lang/en/credits.php.
 *
 * Ukrainian has three plural forms: 1 кредит, 2 кредити, 5 кредитів.
 */

return [
    'name' => 'кредит|кредити|кредитів',
    'amount' => ':count кредит|:count кредити|:count кредитів',
    'title' => 'Кредити',

    'kinds' => [
        'hold' => 'Утримано під роботу',
        'charge' => 'Списано',
        'release' => 'Повернуто з утримання',
        'credit' => 'Нараховано',
        'debit' => 'Витрачено',
        'adjust' => 'Баланс виправлено',
    ],

    'reasons' => [],
];
