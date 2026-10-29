<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Billing\Actions\ValidatePromoCodeAction;
use Dmitryisaenko\LaraFoundry\Billing\Http\Requests\ValidatePromoCodeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The public "check this promo code" endpoint (sub-phase B5).
 *
 * Backs a checkout UI that wants to confirm a code and preview the saving before
 * sending the buyer to the gateway. It is RATE-LIMITED (the route attaches the
 * `larafoundry-billing-promo` limiter) because the donor's equivalent had none and
 * an unthrottled validate endpoint is a code-enumeration oracle.
 *
 * The redeemer is the AUTHENTICATED user (server-side), never a request field, so a
 * personal code cannot be probed against someone else's id. On any failure it
 * returns ONE generic 422 — it never says whether the code was missing, expired,
 * exhausted or personal-mismatched.
 */
class ValidatePromoController extends Controller
{
    public function __invoke(
        ValidatePromoCodeRequest $request,
        ValidatePromoCodeAction $action,
    ): JsonResponse {
        $userId = $request->user()?->getAuthIdentifier();

        $result = $action->execute(
            code: (string) $request->validated('code'),
            userId: is_numeric($userId) ? (int) $userId : null,
            previewAmount: $request->filled('amount') ? (int) $request->validated('amount') : null,
        );

        if (! $result->valid) {
            // Single generic message — no detail about which check failed.
            return response()->json([
                'valid' => false,
                'message' => __('larafoundry::billing.promo.invalid'),
            ], 422);
        }

        return response()->json([
            'valid' => true,
            'kind' => $result->kind,
            // Marked an estimate: the gateway computes the real charged amount.
            'preview_discount' => $result->previewDiscount,
            'preview_is_estimate' => $result->previewDiscount !== null,
        ]);
    }
}
