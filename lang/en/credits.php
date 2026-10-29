<?php

declare(strict_types=1);

/*
 * The credits module (v0.46.0).
 *
 * `name` and `amount` are the currency's name with every plural form. A host
 * that calls its currency something else overrides this file in
 * `lang/vendor/larafoundry/{locale}/credits.php` - the code never spells the
 * word itself, so nothing else changes.
 *
 * `kinds` label a ledger row by what it did; `reasons` is where a host adds its
 * own wording per reason (`reasons.purchase` => 'Purchase'), which then wins
 * over the kind's label.
 */

return [
    'name' => 'credit|credits',
    'amount' => ':count credit|:count credits',
    'title' => 'Credits',

    'kinds' => [
        'hold' => 'Held for work',
        'charge' => 'Charged',
        'release' => 'Returned from hold',
        'credit' => 'Added',
        'debit' => 'Spent',
        'adjust' => 'Balance corrected',
    ],

    'reasons' => [],
];
