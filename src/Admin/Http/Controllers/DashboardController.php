<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin operator-console dashboard — the console's landing screen
 * (phase 3.4).
 *
 * Reachable only through the `larafoundry.admin` gate (super-admin via
 * VisitorStatus). The page is just a frame: it asks the {@see DashboardBuilder}
 * for the widget list the current user may see — FREE core widgets (users /
 * companies / activity) plus whatever a host or the billing add-on registered
 * through the same seam — and hands them to Vue, which renders each widget's
 * component from the frontend registry. The controller knows nothing about which
 * widgets exist, exactly like {@see UserController}/{@see CompanyController} know
 * nothing about which menu items exist.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected DashboardBuilder $dashboard,
    ) {}

    /**
     * Render the dashboard with its server-built, permission-filtered widgets.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'widgets' => $this->dashboard->build('admin', $request->user()),
        ]);
    }
}
