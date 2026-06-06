<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Profile\Http\Requests\UpdateUiSettingRequest;
use Dmitryisaenko\LaraFoundry\Profile\Support\UiSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Persists one per-user UI preference into `users.ui_settings` (phase 5.1).
 *
 * The {@see UpdateUiSettingRequest} has already proven the key is allowlisted
 * and the value fits its type/enum, so this only casts and stores. The write
 * also prunes any non-allowlisted keys that may linger in the column, so the row
 * converges on the allowlist over time (defence in depth, recon finding #2).
 */
class UiSettingsController extends Controller
{
    public function update(UpdateUiSettingRequest $request): RedirectResponse
    {
        $user = $request->user();
        $key = (string) $request->input('key');

        $settings = $user->ui_settings ?? [];
        $settings = is_array($settings) ? $settings : [];

        // Keep only registered keys, then set the new one (cast to its type).
        $settings = array_intersect_key($settings, UiSettings::registry());
        $settings[$key] = UiSettings::cast($key, $request->input('value'));

        $user->forceFill(['ui_settings' => $settings])->save();

        return back()->with('status', __('larafoundry::profile.ui_settings.saved'));
    }
}
