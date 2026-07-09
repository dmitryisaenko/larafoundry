<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payments screen in the super-admin console (phase 7 host request).
 *
 * A deliberate STUB: the core ships the billing seam (PaymentGatewayInterface +
 * NullGateway) but no payment records of its own, so there is nothing to list
 * until a host wires a real gateway (or installs the paid billing add-on, which
 * supplies its own richer console). This screen reserves the `admin.payments.*`
 * route and menu slot and shows an empty state, so a host does not have to add
 * the surface itself. It passes `billing_enabled` so the copy can distinguish
 * "no gateway wired yet" from "wired, no payments yet".
 *
 * Reachable only through the same `larafoundry.admin` + OTP step-up as the rest
 * of the console (the route group applies the gates).
 */
class PaymentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Payments/Index', [
            'billing_enabled' => (bool) config('larafoundry.billing.enabled', false),
        ]);
    }
}
