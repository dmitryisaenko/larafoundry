<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Support;

use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed;
use Illuminate\Http\Request;

/**
 * Builds the {@see AdminAccessAttemptFailed} event from the current request.
 *
 * Centralises the request-side data collection (IP, User-Agent and a
 * dependency-free device fingerprint) so all three failure sources — password,
 * admin OTP and PIN — assemble the signal the same way. Device resolution is
 * synchronous and offline ({@see UserAgentDeviceResolver}); no geo lookup is
 * done here to keep the failure hot-path free of network latency (decision
 * D-2).
 */
class AdminAccessFailureContext
{
    public function __construct(
        protected DeviceFingerprintResolver $devices,
    ) {}

    /**
     * Build the event for the given step and (optional) targeted admin email.
     *
     * @param  'password'|'lockout'|'admin_otp'|'pin'  $step
     */
    public function build(string $step, ?string $email = null): AdminAccessAttemptFailed
    {
        $request = $this->request();

        return new AdminAccessAttemptFailed(
            step: $step,
            ip: (string) ($request?->ip() ?? ''),
            userAgent: (string) ($request?->userAgent() ?? ''),
            device: $request !== null ? $this->devices->resolve($request) : null,
            email: $email,
        );
    }

    /**
     * Build and dispatch the event for the given step in one call.
     *
     * @param  'password'|'lockout'|'admin_otp'|'pin'  $step
     */
    public function dispatch(string $step, ?string $email = null): void
    {
        event($this->build($step, $email));
    }

    protected function request(): ?Request
    {
        $request = request();

        return $request instanceof Request ? $request : null;
    }
}
