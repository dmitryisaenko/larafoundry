<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Events;

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once a super-admin broadcast has finished fanning out to its audience
 * (phase 4.1). Registered in the activity-log event registry, so an operator
 * broadcast is audited; `getLogProperties()` enriches the log entry.
 */
class BroadcastNotificationSent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Notification $notification,
    ) {}

    /**
     * Extra properties merged into the activity-log entry.
     *
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'notification_id' => $this->notification->getKey(),
            'code' => $this->notification->code,
            'recipients' => $this->notification->users()->count(),
        ];
    }
}
