<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry email-template strings (namespace: larafoundry::email)
|--------------------------------------------------------------------------
| Server-side flash / validation messages for the email-template editor
| (phase 5.1). Frontend labels are translated by vue-i18n (English-as-key).
| Hosts override via `vendor:publish --tag=larafoundry-lang`.
*/

return [

    'saved' => 'The email template has been saved.',
    'created' => 'The marketing email template has been created.',
    'duplicated' => 'A marketing copy of the template has been created.',
    'deleted' => 'The marketing email template has been deleted.',
    'test_sent' => 'A test email has been sent to :email.',
    'inactive' => 'This template is switched off, so no test email was sent.',
    'unknown_variables' => 'These variables are not available for this template: :vars.',
    'code_reserved' => 'This code is reserved by a built-in transactional template.',
    'code_taken' => 'A template with this code already exists.',

];
