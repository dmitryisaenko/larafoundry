<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Contracts;

use Dmitryisaenko\LaraFoundry\Auth\Support\DeviceFingerprint;
use Illuminate\Http\Request;

/**
 * Resolves a device fingerprint (type/name/os/browser) from a request.
 *
 * The core ships a dependency-free default ({@see
 * \Dmitryisaenko\LaraFoundry\Auth\Support\UserAgentDeviceResolver}). A host that
 * wants richer parsing can bind its own implementation (e.g. backed by
 * whichbrowser or a device-detector library) to this contract.
 */
interface DeviceFingerprintResolver
{
    public function resolve(Request $request): DeviceFingerprint;
}
