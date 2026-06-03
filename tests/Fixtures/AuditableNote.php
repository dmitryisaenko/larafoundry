<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Fixtures;

use Dmitryisaenko\LaraFoundry\ActivityLog\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture exercising the model-audit {@see LogsActivity} trait (phase 2.1).
 *
 * Reuses the `notes` table; only the trait differs from {@see Note}, so the test
 * can prove the trait produces a real created/updated/deleted diff with the
 * core's device/HTTP context.
 */
class AuditableNote extends Model
{
    use LogsActivity;

    protected $table = 'notes';

    protected $guarded = [];
}
