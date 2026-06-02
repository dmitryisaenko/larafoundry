<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Http\Requests;

/**
 * Update an editable company role (name, description, permission set).
 *
 * The input contract is identical to creating one, so it inherits the rules from
 * {@see StoreRoleRequest}; the difference is purely which route/gate applies
 * (enforced in the controller). Kept as its own type so the two can diverge later
 * without touching call sites.
 */
class UpdateRoleRequest extends StoreRoleRequest {}
