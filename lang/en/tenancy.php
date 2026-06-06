<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry tenancy strings (namespace: larafoundry::tenancy)
|--------------------------------------------------------------------------
| Owned by the core so tenancy ships localised out of the box. Hosts override
| via `vendor:publish --tag=larafoundry-lang`.
*/

return [

    'no_active_company' => 'Select or create a company first.',
    'company_blocked' => 'This company has been blocked. Contact support.',
    'company_created' => 'Company created.',
    'company_switched' => 'You are now working as :company.',
    'setup_complete' => 'Your company is ready.',

    'invitation_sent' => 'Invitation sent.',
    'invitation_revoked' => 'Invitation revoked.',
    'employee_removed' => 'Employee removed.',
    'removal_requested' => 'Your removal request has been sent.',
    'removal_cancelled' => 'Your removal request has been cancelled.',
    'owner_cannot_leave' => 'A company owner cannot leave their own company.',
    'super_admin_cannot_own' => 'Platform administrators cannot create or own a company.',

    'invitation' => [
        'subject' => 'You have been invited to join :company',
        'intro' => 'You have been invited to join :company. Click the button below to accept.',
        'action' => 'Accept invitation',
        'outro' => 'If you were not expecting this invitation, you can ignore this email.',
        'invalid' => 'This invitation is no longer valid.',
        'email_mismatch' => 'This invitation was sent to a different email address.',
        'accepted' => 'You have joined :company.',
        'rejected' => 'Invitation declined.',
    ],

];
