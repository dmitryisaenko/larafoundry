<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Events;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a custom company role is created (phase 1, activity completeness).
 *
 * Registered in the activity-log registry so RBAC changes are audited; the causer
 * is the acting user (resolved from auth by the listener), the subject is the
 * role. `getLogProperties()` enriches the entry.
 */
class RoleCreated
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
