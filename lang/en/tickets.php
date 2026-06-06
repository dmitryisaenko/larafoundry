<?php

declare(strict_types=1);

/*
 * Helpdesk server-side strings (phase 4.2): flash messages and the system
 * notification wording pushed to a ticket's author (phase 4.1 integration).
 * Category / label / status / priority LABELS shown in Vue live in the frontend
 * JSON (lang/frontend/*.json), not here.
 */
return [

    // Flash messages.
    'created' => 'Ticket created successfully.',
    'message_sent' => 'Your reply has been sent.',
    'updated' => 'Ticket updated successfully.',
    'replied' => 'Reply sent successfully.',
    'closed' => 'Ticket closed successfully.',
    'priority_updated' => 'Priority updated successfully.',
    'category_added' => 'Category added.',
    'category_removed' => 'Category removed.',
    'label_added' => 'Label added.',
    'label_removed' => 'Label removed.',

    // In-app notification wording (system notification keys + :title param).
    'notify' => [
        'reply' => [
            'title' => 'New reply to your ticket ":title"',
            'body' => 'The support team has replied. Open the ticket to read the answer.',
        ],
        'opened' => [
            'title' => 'A support ticket was opened for you: ":title"',
            'body' => 'The support team opened a ticket on your behalf.',
        ],
        'action_view' => 'View ticket',
    ],

];
