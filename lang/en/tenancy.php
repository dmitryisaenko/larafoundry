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
    'company_archived' => 'This company has been archived. Ask its owner to restore it.',
    'company_archived_done' => 'Company archived.',
    'company_unarchived' => 'Company restored.',
    'company_created' => 'Company created.',
    'company_switched' => 'You are now working as :company.',
    'setup_complete' => 'Your company is ready.',

    'invitation_sent' => 'Invitation sent.',
    'invitation_revoked' => 'Invitation revoked.',
    'employee_created' => 'Employee created.',
    'employee_updated' => 'Employee updated.',
    'employee_removed' => 'Employee removed.',
    'removal_requested' => 'Your removal request has been sent.',
    'removal_cancelled' => 'Your removal request has been cancelled.',
    'removal_rejected' => 'The removal request has been rejected. The member stays in the company.',
    'owner_cannot_leave' => 'A company owner cannot leave their own company.',
    'super_admin_cannot_own' => 'Platform administrators cannot create or own a company.',

    /*
     * Static fallback subject/line for the lifecycle emails, used ONLY when the
     * matching HTML registry template is switched off (decision D-5.1-8). The
     * normal render comes from config/larafoundry-email.php.
     */
    'notify_mail' => [
        'invitation_accepted_owner' => [
            'subject' => 'Someone joined :company',
            'line' => ':member accepted your invitation and joined :company.',
        ],
        'invitation_rejected_owner' => [
            'subject' => 'Invitation to :company was declined',
            'line' => 'The invitation you sent to :email to join :company was declined.',
        ],
        'employee_joined' => [
            'subject' => 'You have joined :company',
            'line' => 'You have joined :company. You can now sign in and start working with your team.',
        ],
        'employee_removed' => [
            'subject' => 'You have been removed from :company',
            'line' => 'Your request to leave :company has been approved and you have been removed.',
        ],
        'company_created' => [
            'subject' => ':company is ready',
            'line' => 'Your company :company has been created.',
        ],
        'company_deleted' => [
            'subject' => ':company has been archived',
            'line' => 'Your company :company has been archived. You can restore it at any time.',
        ],
    ],

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
