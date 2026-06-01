<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

/**
 * Core Inertia shared-props middleware.
 *
 * Shares only cross-cutting infrastructure every page needs: flash messages,
 * the active locale, its translation bag, Ziggy routing and the appearance
 * preference. This is the backend half of the core contract consumed by the
 * frontend (`app.js` reads `locale` + `translations` to boot vue-i18n; the
 * `flash` shape feeds AppFlashMessage; `ziggy` powers `route()`).
 *
 * Host apps extend this class and merge their own props (auth, tenancy,
 * navigation, business data):
 *
 *     class HandleInertiaRequests extends \Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests
 *     {
 *         public function share(Request $request): array
 *         {
 *             return [
 *                 ...parent::share($request),
 *                 'auth' => fn () => $request->user(),
 *                 // …
 *             ];
 *         }
 *     }
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => fn () => $this->flash($request),
            'locale' => fn () => App::getLocale(),
            'translations' => fn () => $this->translations(),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'appearance' => fn () => $request->cookie('appearance') ?? 'system',
        ];
    }

    /**
     * One-shot flash payload pulled from the session.
     *
     * @return array<string, mixed>
     */
    protected function flash(Request $request): array
    {
        $session = $request->session();

        return [
            'info' => $session->pull('message-info'),
            'error' => $session->pull('message-error'),
            'status' => $session->pull('status'),
            'disappear_info' => $session->pull('message-disappear-info'),
            'disappear_error' => $session->pull('message-disappear-error'),
        ];
    }

    /**
     * Translation bag for the active locale.
     *
     * Merges `lang/{locale}.json` with every `lang/{locale}/*.php` group,
     * matching Laravel's own loader layout so existing translation files work
     * unchanged.
     *
     * @return array<string, mixed>
     */
    protected function translations(): array
    {
        $locale = App::getLocale();
        $data = [];

        $jsonPath = base_path("lang/{$locale}.json");
        if (File::exists($jsonPath)) {
            $json = json_decode((string) File::get($jsonPath), true);
            if (is_array($json)) {
                $data = $json;
            }
        }

        $dir = base_path("lang/{$locale}");
        if (File::isDirectory($dir)) {
            foreach (File::files($dir) as $file) {
                $group = $file->getFilenameWithoutExtension();
                $data[$group] = Lang::get($group);
            }
        }

        return $data;
    }
}
