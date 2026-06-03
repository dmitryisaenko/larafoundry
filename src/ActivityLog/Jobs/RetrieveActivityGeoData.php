<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Jobs;

use Dmitryisaenko\LaraFoundry\ActivityLog\Contracts\GeoResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enrich one activity row with geo data off the request path (phase 2.1).
 *
 * Dispatched by the service after writing the entry (only when geo is enabled).
 * Resolution is fail-soft inside the resolver; this job's own failure() path is
 * the last resort that stamps "Unknown" so a row is never left half-enriched.
 *
 * Queue policy: the core targets database queues + cron (no mandatory Redis),
 * so this is a plain ShouldQueue with a short timeout and a few retries.
 */
class RetrieveActivityGeoData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public int $activityId,
        public string $ip,
    ) {}

    public function handle(GeoResolver $geo): void
    {
        $activity = $this->findActivity();

        if ($activity === null) {
            return;
        }

        $data = $geo->resolve($this->ip);

        $activity->forceFill([
            'geo_country' => $data['country'],
            'geo_city' => $data['city'],
            'geo_updated_at' => now(),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        Log::error("LaraFoundry geo job failed for activity {$this->activityId}: ".$exception->getMessage());

        $activity = $this->findActivity();

        if ($activity === null) {
            return;
        }

        // Only mark Unknown for a row that was never enriched. The resolver is
        // fail-soft, so a thrown exception here means the WRITE failed (e.g. a
        // transient deadlock) — possibly after resolve() already produced a real
        // country. Blindly stamping Unknown would clobber correct geo data.
        if ($activity->geo_updated_at !== null) {
            return;
        }

        $activity->forceFill([
            'geo_country' => __('larafoundry::activitylog.geo.unknown'),
            'geo_city' => __('larafoundry::activitylog.geo.unknown'),
            'geo_updated_at' => now(),
        ])->save();
    }

    /**
     * Resolve the configured activity model and find the row by id.
     */
    protected function findActivity(): ?Model
    {
        /** @var class-string<Model> $model */
        $model = config('activitylog.activity_model');

        return $model::query()->find($this->activityId);
    }
}
