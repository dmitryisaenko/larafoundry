<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a user updates their own profile information (phase 1, activity
 * completeness). `$user` is both the actor and the target — the activity-log
 * listener resolves it as the causer, and `email_changed` records whether this
 * edit moved the (security-sensitive) email address.
 */
class ProfileUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly bool $emailChanged = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'email_changed' => $this->emailChanged,
        ];
    }
}
