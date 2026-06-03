<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Concerns;

use Dmitryisaenko\LaraFoundry\ActivityLog\Support\ActivityContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity as SpatieLogsActivity;

/**
 * Model-audit trait: automatic created/updated/deleted logging with a real
 * old -> new diff plus the core's actor/device/HTTP context (phase 2.1).
 *
 * This REPLACES the donor's broken trait. The donor:
 *   - overrode bootLogsActivity() WITHOUT calling parent, so spatie's engine
 *     never booted and logOnlyDirty()/dontSubmitEmptyLogs() were dead code;
 *   - called a non-existent CustomActivity::getUserData(), fataling on first
 *     save;
 *   - hand-built the record, writing every attribute instead of the dirty diff.
 *
 * The fix is to use spatie's native machinery:
 *   - getActivitylogOptions() declares WHAT to log (dirty attributes only);
 *   - tapActivity() — which spatie invokes on the subject just before save —
 *     decorates the entry with our request context. No engine override.
 *
 * A host model opts in with `use LogsActivity;`. Customise scope by overriding
 * getActivitylogOptions() (e.g. logOnly([...]) instead of logAll()).
 */
trait LogsActivity
{
    use SpatieLogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Decorate the pending activity with request context.
     *
     * Spatie calls this on the subject (this model) right before saving the
     * entry, so the entry already carries the dirty diff in `properties`; we
     * only add the device/ip/route columns the core records everywhere.
     *
     * @param  Model  $activity  the pending activity model
     */
    public function tapActivity(Model $activity, string $eventName): void
    {
        $context = app(ActivityContext::class)->forRequest();

        $activity->forceFill(array_merge($context, [
            'is_successful' => true,
            'response_code' => 200,
        ]));
    }
}
