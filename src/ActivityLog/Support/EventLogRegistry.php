<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Support;

/**
 * Read-only accessor over the activity-log event registry config (phase 2.1).
 *
 * The single read-point so the listener and any UI see the same registry —
 * `config('larafoundry-activitylog.events')`, the core default merged with the
 * host's published file. Parallels {@see
 * \Dmitryisaenko\LaraFoundry\Authorization\Support\PermissionCatalog} for the
 * permissions catalog. Nothing here touches the database.
 */
class EventLogRegistry
{
    /**
     * The raw registry: event-class => ['group','description','code'].
     *
     * @return array<class-string, array{group: string, description: string, code: int}>
     */
    public function all(): array
    {
        return config('larafoundry-activitylog.events', []);
    }

    /**
     * The event classes to subscribe to.
     *
     * @return list<class-string>
     */
    public function eventClasses(): array
    {
        return array_keys($this->all());
    }

    /**
     * Metadata for one event class, or null when it is not registered.
     *
     * @return array{group: string, description: string, code: int}|null
     */
    public function metaFor(string $eventClass): ?array
    {
        return $this->all()[$eventClass] ?? null;
    }
}
