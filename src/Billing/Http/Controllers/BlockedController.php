<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Billing\Actions\GetBlockStatusAction;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Renders the "account blocked" page (sub-phase B7a) — one page, several modes.
 *
 * Both the core (super-admin block, via `larafoundry.auth.blocked_redirect_route`) and
 * the add-on's `billing.access` (owner-ban / hard payment-required) redirect here with a
 * `type`, so there is one route and one Vue page for every "this account is unavailable"
 * reason. The type is validated against a fixed set (never rendered raw), and the live
 * status is recomputed server-side rather than trusted from the query string.
 *
 * The subject whose status is shown comes from {@see BillingSubject}: the active company
 * in `teams` mode, the signed-in user in `personal` — where `getActiveCompany()` is
 * permanently null and this page used to render an empty shell. The prop names stay
 * `companyName` / `blockStatus` so the published Vue page keeps working unchanged.
 */
final class BlockedController
{
    private const TYPES = [
        'payment_required',
        'payment_required_non_owner',
        'owner_banned',
        'company_blocked_admin',
        'user_banned',
    ];

    public function __construct(private readonly GetBlockStatusAction $blockStatus) {}

    public function __invoke(Request $request): InertiaResponse
    {
        $subject = BillingSubject::for($request->user());

        $type = (string) $request->query('type', '');
        $type = in_array($type, self::TYPES, true) ? $type : null;

        return Inertia::render('Billing/Blocked', [
            'blockType' => $type,
            'blockStatus' => $subject === null ? null : ($this->blockStatus)($subject),
            // The payer's display name: the company in `teams`, the person in `personal`.
            'companyName' => BillingSubject::label($subject),
            'pricingRoute' => 'billing.pricing',
        ]);
    }
}
