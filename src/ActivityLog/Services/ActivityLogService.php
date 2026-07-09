<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Services;

use Dmitryisaenko\LaraFoundry\ActivityLog\Jobs\RetrieveActivityGeoData;
use Dmitryisaenko\LaraFoundry\ActivityLog\Support\ActivityContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes activity-log entries with the core's actor / device / HTTP context
 * (phase 2.1).
 *
 * An injectable instance service (not the donor's statics) so it is testable and
 * its dependencies are explicit. Resolve it from the container or via the
 * `Activity` facade.
 *
 * Two donor bugs are fixed here by design:
 *  - causer and subject are DISTINCT (the donor wrote the same user id to both);
 *  - PII is redacted from `full_url` and `properties` (via {@see ActivityContext}).
 */
class ActivityLogService
{
    public function __construct(
        protected ActivityContext $context,
    ) {}

    /**
     * Log a registered domain event (driven by the config registry).
     *
     * Geo is enriched ASYNCHRONOUSLY (a queued job) so the listener never blocks
     * the request on an outbound HTTP call.
     *
     * @param  object  $event  the event instance (may carry a `user`/`email`)
     * @param  array<string, mixed>  $properties
     */
    public function logEvent(
        object $event,
        string $eventClassName,
        string $group,
        string $description,
        int $code,
        array $properties = [],
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $causer = $this->resolveCauser($event);
        $emails = $this->resolveEmails($event, $causer);
        $subject = $this->resolveSubject($event, $causer);

        $activity = $this->write(
            logName: $group,
            description: $description,
            event: $eventClassName,
            causer: $causer,
            subject: $subject,
            properties: array_merge($properties, ['event_group' => $group]),
            isSuccessful: $code < 400,
            responseCode: $code,
            emails: $emails,
        );

        $this->dispatchGeo($activity);
    }

    /**
     * Log a one-off custom activity (manual API).
     *
     * Geo defaults to SYNCHRONOUS (parity with the donor's manual path); callers
     * on an interactive request path that must not block on an outbound geo HTTP
     * call pass `geoSync: false` to enqueue it instead (the admin console does
     * this, so a slow geo provider never delays an operator action). The caller
     * is the causer; an explicit domain object may be the subject.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $description,
        string $logName = 'custom',
        array $properties = [],
        ?Model $subject = null,
        bool $isSuccessful = true,
        int $responseCode = 200,
        bool $geoSync = true,
        ?Authenticatable $causer = null,
    ): Model {
        // Default to the web-guard user; callers on a non-default guard (e.g. the
        // QR verify endpoint authenticated via the `sanctum` token guard, where
        // Auth::user() on the default guard is null) pass the resolved causer in.
        $causer ??= Auth::user();

        $activity = $this->write(
            logName: $logName,
            description: $description,
            event: null,
            causer: $causer,
            subject: $subject,
            properties: $properties,
            isSuccessful: $isSuccessful,
            responseCode: $responseCode,
            emails: $this->resolveEmails(new \stdClass, $causer),
        );

        $this->dispatchGeo($activity, sync: $geoSync);

        return $activity;
    }

    /**
     * Log the execution of an instrumented method (extract of the donor helper).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function logMethodExecution(
        string $methodName,
        mixed $result,
        array $before = [],
        array $after = [],
    ): Model {
        $isException = $result instanceof \Throwable;

        return $this->log(
            description: "method_execution_{$methodName}",
            logName: 'method_execution',
            properties: array_filter([
                'method' => $methodName,
                'before' => $before,
                'after' => $after,
                'error' => $isException ? $result->getMessage() : null,
            ], fn ($v) => $v !== null),
            isSuccessful: ! $isException,
            responseCode: $isException ? 500 : 200,
        );
    }

    /**
     * Build and persist one entry, with request context and PII redaction.
     *
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $emails
     */
    protected function write(
        string $logName,
        string $description,
        ?string $event,
        ?Authenticatable $causer,
        ?Model $subject,
        array $properties,
        bool $isSuccessful,
        int $responseCode,
        array $emails,
    ): Model {
        $context = $this->context->forRequest();

        /** @var class-string<Model> $model */
        $model = config('activitylog.activity_model');

        $attributes = array_merge($context, [
            'log_name' => $logName,
            'description' => $description,
            'event' => $event,
            'properties' => $this->context->redactProperties($properties),
            'is_successful' => $isSuccessful,
            'response_code' => $responseCode,
            'user_email' => $emails,
        ]);

        if ($causer instanceof Model) {
            $attributes['causer_type'] = $causer->getMorphClass();
            $attributes['causer_id'] = $causer->getKey();
        }

        // Subject is the OBJECT of the action — distinct from the causer. Auth
        // events have no subject; that is correct and stays null.
        if ($subject instanceof Model) {
            $attributes['subject_type'] = $subject->getMorphClass();
            $attributes['subject_id'] = $subject->getKey();
        }

        return $model::query()->create($attributes);
    }

    /**
     * Dispatch the geo-enrichment job for an entry, unless geo is disabled or
     * there is no usable IP.
     */
    protected function dispatchGeo(Model $activity, bool $sync = false): void
    {
        if (! config('larafoundry-activitylog.geo.enabled', true)) {
            return;
        }

        $ip = (string) ($activity->user_ip ?? '');

        if ($ip === '') {
            return;
        }

        $job = new RetrieveActivityGeoData((int) $activity->getKey(), $ip);

        $sync ? dispatch_sync($job) : dispatch($job);
    }

    /**
     * The acting user behind an event: its `user` property, the current auth
     * user, or a lookup by the event's email. Never the subject.
     */
    protected function resolveCauser(object $event): ?Authenticatable
    {
        if (isset($event->user) && $event->user instanceof Authenticatable) {
            return $event->user;
        }

        if (Auth::check()) {
            return Auth::user();
        }

        $email = $this->emailFromEvent($event);

        if ($email !== null) {
            return $this->findUserByEmail($email);
        }

        return null;
    }

    /**
     * The object the action was performed on, when the event carries one and it
     * is not the causer itself. Returns null for actor-only events (auth).
     */
    protected function resolveSubject(object $event, ?Authenticatable $causer): ?Model
    {
        // Order matters: the most-specific OBJECT of the action wins. An event
        // like EmployeeRemoved carries both `company` (context) and `employee`
        // (the actual subject), so the specific entities must be checked before
        // the surrounding company. Single-subject events (e.g. CompanyCreated,
        // which only has `company`) are unaffected.
        foreach (['subject', 'employee', 'invitation', 'ticket', 'role', 'model', 'company'] as $property) {
            if (isset($event->{$property}) && $event->{$property} instanceof Model) {
                $candidate = $event->{$property};

                // Do not record the causer as its own subject.
                if ($causer instanceof Model && $this->isSameModel($candidate, $causer)) {
                    continue;
                }

                return $candidate;
            }
        }

        return null;
    }

    /**
     * The actor email(s) for an entry, as an array (events without an
     * authenticated user, e.g. a failed login, still carry a candidate email).
     *
     * @return list<string>
     */
    protected function resolveEmails(object $event, ?Authenticatable $causer): array
    {
        $emails = [];

        if ($causer !== null && method_exists($causer, 'getAttribute')) {
            $email = $causer->getAttribute('email');

            if (is_string($email) && $email !== '') {
                $emails[] = $email;
            }
        }

        $eventEmail = $this->emailFromEvent($event);

        if ($eventEmail !== null) {
            $emails[] = $eventEmail;
        }

        return array_values(array_unique($emails));
    }

    /**
     * Pull a candidate email off an event (top-level property or in credentials).
     */
    protected function emailFromEvent(object $event): ?string
    {
        if (isset($event->email) && is_string($event->email)) {
            return $event->email;
        }

        if (isset($event->credentials['email']) && is_string($event->credentials['email'])) {
            return $event->credentials['email'];
        }

        return null;
    }

    protected function findUserByEmail(string $email): ?Authenticatable
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        $user = $model::query()->where('email', $email)->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    protected function isSameModel(Model $a, Model $b): bool
    {
        return $a->getMorphClass() === $b->getMorphClass()
            && $a->getKey() === $b->getKey();
    }

    protected function enabled(): bool
    {
        return (bool) config('larafoundry-activitylog.enabled', true);
    }
}
