<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Affiliates screen in the super-admin console (phase 4 monetization stub).
 *
 * A deliberate STUB: the affiliate program is a feature of the paid billing
 * add-on (`dmitryisaenko/larafoundry-billing`), not the free core. This screen
 * reserves the `admin.affiliates.*` route and menu slot and shows an empty
 * state, so a host does not have to add the surface itself; when the add-on is
 * installed it overrides this screen with its own richer console. It passes
 * `billing_enabled` (so the copy can react to the master switch) and `upsell_url`
 * (where the free core points operators to obtain the paid add-on).
 *
 * Reachable only through the same `larafoundry.admin` + OTP step-up as the rest
 * of the console (the route group applies the gates).
 */
class AffiliateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Affiliates/Index', [
            'billing_enabled' => (bool) config('larafoundry.billing.enabled', false),
            'upsell_url' => config('larafoundry.upsell.billing_url'),
        ]);
    }
}
