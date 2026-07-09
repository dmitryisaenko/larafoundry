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

    /*
     * In-app system-notification wording for the owner-employee lifecycle
     * (phase 2a). Titles/bodies are stored as translation keys and resolved per
     * reader; :params carry the company / member / email context. The core sets
     * an "info" visual type for all of them.
     */
    'tenancy' => [
        'action_view_team' => 'View team',
        'action_view_home' => 'Open',

        'invited' => [
            'owner' => [
                'title' => 'You invited :email',
                'body' => 'An invitation to join :company was sent to :email.',
            ],
            'user' => [
                'title' => 'You were invited to :company',
                'body' => ':inviter invited you to join :company.',
            ],
        ],
        'accepted' => [
            'owner' => [
                'title' => ':member joined :company',
                'body' => ':member accepted your invitation and joined :company.',
            ],
            'user' => [
                'title' => 'You joined :company',
                'body' => 'You now have access to :company.',
            ],
        ],
        'rejected' => [
            'owner' => [
                'title' => 'Invitation to :company declined',
                'body' => ':email declined your invitation to join :company.',
            ],
            'user' => [
                'title' => 'Invitation declined',
                'body' => 'You declined the invitation to join :company.',
            ],
        ],
        'removed' => [
            'owner' => [
                'title' => ':member was removed from :company',
                'body' => ':member no longer has access to :company.',
            ],
            'user' => [
                'title' => 'You were removed from :company',
                'body' => 'You no longer have access to :company.',
            ],
        ],
        'removal_requested' => [
            'owner' => [
                'title' => ':member asked to leave :company',
                'body' => ':member requested removal from :company. You can approve or reject it.',
            ],
            'user' => [
                'title' => 'Removal request sent',
                'body' => 'Your request to leave :company was sent to the owner.',
            ],
        ],
        'removal_cancelled' => [
            'owner' => [
                'title' => ':member withdrew their removal request',
                'body' => ':member cancelled the request to leave :company.',
            ],
            'user' => [
                'title' => 'Removal request withdrawn',
                'body' => 'You cancelled your request to leave :company.',
            ],
        ],
        'removal_rejected' => [
            'owner' => [
                'title' => 'You rejected a removal request',
                'body' => ':member stays in :company.',
            ],
            'user' => [
                'title' => 'Your removal request was rejected',
                'body' => 'You remain a member of :company.',
            ],
        ],
        'invitation_withdrawn' => [
            'owner' => [
                'title' => 'Invitation withdrawn',
                'body' => 'The invitation to :email for :company was withdrawn.',
            ],
        ],
        'invitation_resent' => [
            'owner' => [
                'title' => 'Invitation resent',
                'body' => 'The invitation to :email for :company was resent.',
            ],
        ],
        'role_changed' => [
            'user' => [
                'title' => 'Your access in :company changed',
                'body' => 'The owner updated your roles in :company.',
            ],
        ],
        'company_created' => [
            'owner' => [
                'title' => ':company is ready',
                'body' => 'Your company :company has been created.',
            ],
        ],
        'company_archived' => [
            'owner' => [
                'title' => ':company was archived',
                'body' => 'Your company :company has been archived. You can restore it at any time.',
            ],
        ],
    ],
];
