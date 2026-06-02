<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Support;

/**
 * Immutable value object describing the device behind a request.
 */
final class DeviceFingerprint
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $name = null,
        public readonly ?string $os = null,
        public readonly ?string $browser = null,
    ) {}

    /**
     * Shape used when persisting onto a UserSession row.
     *
     * @return array{user_device_type: ?string, user_device_name: ?string, user_os: ?string, user_browser: ?string}
     */
    public function toSessionAttributes(): array
    {
        return [
            'user_device_type' => $this->type,
            'user_device_name' => $this->name,
            'user_os' => $this->os,
            'user_browser' => $this->browser,
        ];
    }
}
