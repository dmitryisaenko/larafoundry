<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Settings\Http\Controllers\Admin;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Settings\Http\Requests\UpdateSettingRequest;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * App-scope settings in the operator console (phase 5.1), super-admin only.
 *
 * Reachable only through the `larafoundry.admin` gate (+ OTP) on routes/admin.php
 * — so, like the rest of the console, the zone gate is the authority and these
 * actions carry no extra permission slug. Only keys declared with `scope => app`
 * are writable here (fail-closed).
 */
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Settings', [
            'schema' => $this->settings->schemaForScope('app'),
            'values' => $this->settings->allForScope('app'),
        ]);
    }

    public function update(UpdateSettingRequest $request): RedirectResponse
    {
        $key = (string) $request->input('key');
        $definition = $this->settings->definition($key);

        if ($definition === null
            || ($definition['scope'] ?? null) !== 'app'
            || ! ($definition['form'] ?? true)) {
            throw ValidationException::withMessages([
                'key' => __('larafoundry::settings.invalid_key'),
            ]);
        }

        $this->settings->set($key, $request->input('value'), null);

        Activity::log(
            description: 'admin.settings.updated',
            logName: 'admin',
            properties: ['key' => $key],
            geoSync: false,
        );

        return back()->with('status', __('larafoundry::settings.saved'));
    }
}
