<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a user voluntarily changes their own password while logged in
 * (phase 1, activity completeness).
 *
 * Distinct from Laravel's `PasswordReset` (the forgot-password flow, already
 * logged): this is the authenticated in-session change. `$user` is resolved as
 * the causer by the activity-log listener. No secret is carried.
 */
class PasswordUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Authenticatable $user,
    ) {}
}
