<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry activity-log strings (namespace: larafoundry::activitylog)
|--------------------------------------------------------------------------
| Backend strings for the activity log (phase 2.1). The super-admin UI labels
| are translated client-side through vue-i18n (English text as the key); these
| are the server-side values written into log rows (geo fallbacks), kept here so
| they localise with the request locale. Hosts override via
| `vendor:publish --tag=larafoundry-lang`.
*/

return [

    'geo' => [
        'local' => 'Local network',
        'unknown' => 'Unknown',
    ],

];
