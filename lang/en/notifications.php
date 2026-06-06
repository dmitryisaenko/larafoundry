<?php

declare(strict_types=1);

/*
 * Server-side strings for the notification centre (phase 4.1).
 *
 * Flash messages from the super-admin broadcast endpoints. Frontend UI labels
 * live in the English-as-key frontend dictionary (lang/frontend/*.json),
 * translated by vue-i18n — these are the server flashes.
 */

return [
    'broadcast' => [
        'created' => 'Broadcast saved as draft.',
        'updated' => 'Broadcast updated.',
        'queued' => 'Broadcast is being delivered.',
        'deleted' => 'Broadcast deleted.',
        'not_draft' => 'Only a draft broadcast can be edited or sent.',
    ],
];
