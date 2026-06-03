<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Facades;

use Dmitryisaenko\LaraFoundry\ActivityLog\Services\ActivityLogService;
use Illuminate\Support\Facades\Facade;

/**
 * Facade over {@see ActivityLogService} for the manual logging API (phase 2.1).
 *
 * @method static \Illuminate\Database\Eloquent\Model log(string $description, string $logName = 'custom', array $properties = [], ?\Illuminate\Database\Eloquent\Model $subject = null, bool $isSuccessful = true, int $responseCode = 200)
 * @method static \Illuminate\Database\Eloquent\Model logMethodExecution(string $methodName, mixed $result, array $before = [], array $after = [])
 * @method static void logEvent(object $event, string $eventClassName, string $group, string $description, int $code, array $properties = [])
 *
 * @see ActivityLogService
 */
class Activity extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ActivityLogService::class;
    }
}
