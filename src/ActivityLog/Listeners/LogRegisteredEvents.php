<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Listeners;

use Dmitryisaenko\LaraFoundry\ActivityLog\Services\ActivityLogService;
use Dmitryisaenko\LaraFoundry\ActivityLog\Support\EventLogRegistry;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Subscribes the config registry's events to the activity logger (phase 2.1).
 *
 * Registered as a subscriber in the service provider's boot(): for each event
 * class in `larafoundry-activitylog.events`, it forwards the fired event to
 * {@see ActivityLogService::logEvent()} with that event's group/description/code.
 *
 * Mirrors the donor's provider loop, minus its two cross-concerns: the RBAC
 * "authenticated" role assignment (already its own phase-1.3 listener) and the
 * `Activity::unguard()` call (the core model uses a real fillable instead).
 */
class LogRegisteredEvents
{
    public function __construct(
        protected EventLogRegistry $registry,
        protected ActivityLogService $service,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        if (! config('larafoundry-activitylog.enabled', true)) {
            return;
        }

        foreach ($this->registry->all() as $eventClass => $meta) {
            $events->listen($eventClass, function (object $event) use ($eventClass, $meta) {
                $this->handle($event, $eventClass, $meta);
            });
        }
    }

    /**
     * @param  array{group: string, description: string, code: int}  $meta
     */
    protected function handle(object $event, string $eventClass, array $meta): void
    {
        $properties = method_exists($event, 'getLogProperties')
            ? ['event_properties' => $event->getLogProperties()]
            : [];

        // Audit logging must never break the action that triggered it: a
        // transient DB issue (or a missing table) should degrade to a logged
        // warning, not a failed login / failed company creation.
        try {
            $this->service->logEvent(
                event: $event,
                eventClassName: class_basename($eventClass),
                group: $meta['group'],
                description: $meta['description'],
                code: $meta['code'],
                properties: $properties,
            );
        } catch (Throwable $e) {
            Log::warning("LaraFoundry activity log failed for {$eventClass}: ".$e->getMessage());
        }
    }
}
