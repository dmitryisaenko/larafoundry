<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Support;

use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Illuminate\Http\Request;

/**
 * Dependency-free device fingerprinting from the User-Agent header.
 *
 * Deliberately coarse: it classifies the device type and names the OS/browser
 * family from well-known substrings. This covers the "active sessions" UX
 * (so a user recognises their own logins) without dragging a heavyweight
 * parser into the core's dependency tree. A host needing precise detection can
 * bind its own {@see DeviceFingerprintResolver}.
 */
class UserAgentDeviceResolver implements DeviceFingerprintResolver
{
    public function resolve(Request $request): DeviceFingerprint
    {
        $agent = (string) $request->userAgent();

        if ($agent === '') {
            return new DeviceFingerprint;
        }

        return new DeviceFingerprint(
            type: $this->detectType($agent),
            name: $this->detectName($agent),
            os: $this->detectOs($agent),
            browser: $this->detectBrowser($agent),
        );
    }

    protected function detectType(string $agent): string
    {
        // Crawlers first: bot UAs frequently embed mobile/tablet tokens
        // (e.g. Googlebot's mobile UA contains "Android … Mobile"), so checking
        // mobile before robot would misclassify them.
        if (preg_match('/bot|crawl|spider|slurp/i', $agent) === 1) {
            return 'robot';
        }

        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $agent) === 1) {
            return 'tablet';
        }

        if (preg_match('/Mobi|Android.*Mobile|iPhone|iPod|Windows Phone/i', $agent) === 1) {
            return 'mobile';
        }

        return 'desktop';
    }

    protected function detectName(string $agent): ?string
    {
        foreach ([
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Macintosh' => 'Mac',
            'Android' => 'Android device',
            'Windows' => 'Windows PC',
            'Linux' => 'Linux machine',
        ] as $needle => $name) {
            if (str_contains($agent, $needle)) {
                return $name;
            }
        }

        return null;
    }

    protected function detectOs(string $agent): ?string
    {
        return match (true) {
            (bool) preg_match('/Windows NT 10/i', $agent) => 'Windows 10/11',
            (bool) preg_match('/Windows/i', $agent) => 'Windows',
            (bool) preg_match('/iPhone|iPad|iPod/i', $agent) => 'iOS',
            (bool) preg_match('/Mac OS X/i', $agent) => 'macOS',
            (bool) preg_match('/Android/i', $agent) => 'Android',
            (bool) preg_match('/Linux/i', $agent) => 'Linux',
            default => null,
        };
    }

    protected function detectBrowser(string $agent): ?string
    {
        // Order matters: Edge/Chrome/Opera UAs all contain earlier families'
        // tokens, so the most specific must win first.
        return match (true) {
            (bool) preg_match('/Edg/i', $agent) => 'Edge',
            (bool) preg_match('/OPR|Opera/i', $agent) => 'Opera',
            (bool) preg_match('/Firefox/i', $agent) => 'Firefox',
            (bool) preg_match('/Chrome/i', $agent) => 'Chrome',
            (bool) preg_match('/Safari/i', $agent) => 'Safari',
            default => null,
        };
    }
}
