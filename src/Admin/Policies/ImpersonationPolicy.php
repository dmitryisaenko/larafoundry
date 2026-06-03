<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Policies;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorizes impersonation (phase 2.3) — the fix for recon finding #1 (HIGH).
 *
 * The donor left `canImpersonate()` commented out, so Lab404 allowed ANY admin
 * to impersonate ANYONE, with no audit. This policy closes that:
 *
 *  - only a super-admin (VisitorStatus, not a self-rolled flag check) may
 *    impersonate at all;
 *  - a super-admin can NEVER impersonate another admin/super-admin (no
 *    admin-via-admin takeover);
 *  - a blocked or deleted account cannot be impersonated;
 *  - nobody impersonates themselves.
 *
 * The controller still audits both take and leave; this is the gate, the audit
 * is the record.
 */
class ImpersonationPolicy
{
    public function __construct(
        protected VisitorStatus $status,
    ) {}

    /**
     * Whether $admin may impersonate $target.
     */
    public function take(Authenticatable $admin, Authenticatable $target): bool
    {
        // Only super-admins may impersonate.
        if (! $this->status->isAdmin($admin)) {
            return false;
        }

        // Never yourself.
        if ($admin->getAuthIdentifier() === $target->getAuthIdentifier()) {
            return false;
        }

        // Never another admin/super-admin (admin-via-admin escalation).
        if ($this->isAdminFlagged($target)) {
            return false;
        }

        // Never a blocked or deleted account.
        $targetStatus = $this->status->for($target);

        if ($targetStatus === VisitorStatus::BLOCKED || $targetStatus === VisitorStatus::DELETED) {
            return false;
        }

        return true;
    }

    /**
     * Whether the target carries the admin flag, independent of any email
     * allow-list. The allow-list narrows who is treated as the operating
     * super-admin, but for the "cannot impersonate an admin" rule we must block
     * EVERY admin-flagged account, allow-listed or not — otherwise a flagged but
     * non-allow-listed admin would be impersonable, leaking their session.
     */
    protected function isAdminFlagged(Authenticatable $target): bool
    {
        return method_exists($target, 'isAdmin') && $target->isAdmin() === true;
    }
}
