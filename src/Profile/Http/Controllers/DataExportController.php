<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataExportRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the authenticated user's personal-data export as a JSON download
 * (phase 5.3, decision D-5.3-4).
 *
 * Gathers every registered {@see ExportsUserDataProvider} through the registry —
 * each contributes only the data it owns FOR THIS USER — and returns it as a
 * single attachment. There is no id in the URL: a user can only ever export
 * themselves ({@see Request::user()}), so there is no object to enumerate. The
 * route is rate-limited (config `larafoundry.profile.data_export.throttle`,
 * default 3/day) to blunt repeated full-dump pulls.
 */
class DataExportController extends Controller
{
    public function download(Request $request, UserDataExportRegistry $registry): StreamedResponse
    {
        $user = $request->user();

        $payload = [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'user_id' => $user->getAuthIdentifier(),
                'format' => 'larafoundry.user-data-export.v1',
            ],
            'data' => $registry->collect($user),
        ];

        $json = (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $filename = 'my-data-'.$user->getAuthIdentifier().'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }
}
