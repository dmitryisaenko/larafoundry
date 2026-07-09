<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Events;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a custom company role is deleted (phase 1, activity completeness).
 *
 * Dispatched with the in-memory role so its id/name are still readable after the
 * row is gone. The causer is the acting user; the subject is the (now-deleted)
 * role. `getLogProperties()` enriches the entry.
 */
class RoleDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Role $role,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'role_id' => $this->role->getKey(),
            'role_name' => $this->role->name,
            'role_slug' => $this->role->slug,
            'company_id' => $this->role->company_id,
        ];
    }
}
