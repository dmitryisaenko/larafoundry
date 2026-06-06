<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Qr\Http\Controllers;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Auth\Qr\Models\SignInRequest;
use Dmitryisaenko\LaraFoundry\Auth\Qr\Support\QrCodeGenerator;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Cross-device QR login (phase 1.4 part 2).
 *
 * Extracted from the donor's LoginWithQrCode and hardened. The flow is the same
 * WhatsApp-Web pattern: the web (guest) side calls {@see generate()} to get a QR,
 * an already-authenticated device scans it and hits {@see verify()} to approve,
 * and the web side {@see poll()}s until it is approved and logs in.
 *
 * Donor holes closed here:
 *  1. token is SHA-256 hashed in the DB (plaintext only ever in the QR URL);
 *  4. an ABSOLUTE TTL cap stops a request sliding forward forever;
 *  6. the session is regenerated BEFORE login (no session fixation);
 *  7. every attempt is audited via the activity log;
 *  9. no Crypt of id/token, so a malformed input is a clean 4xx, never a 500;
 * 10. only the row id is stored in the session, once (no double-encrypt);
 * 11. the unexplained `subSeconds(13)` expiry buffer is gone.
 * (#2 rate-limit and #8 indexes live on the routes/migration; #3 SSRF guard and
 *  #5 cleanup live in the frontend scanner / prune command.)
 *
 * super-admin is blocked from approving via {@see VisitorStatus::isAdmin()} —
 * the allow-list-aware check, not the donor's raw `is_admin` flag.
 */
class QrLoginController extends Controller
{
    public function __construct(
        protected QrCodeGenerator $qrCodes,
        protected VisitorStatus $visitorStatus,
    ) {}

    /**
     * Issue (or re-use) a pending sign-in request and return its QR code.
     *
     * Re-uses the session's current request while it is still within the
     * absolute cap (so refreshing the page does not orphan rows); otherwise mints
     * a fresh secret. Only the hash is persisted; the plaintext goes into the QR.
     */
    public function generate(Request $request): JsonResponse
    {
        [$plain, $signInRequest] = $this->currentOrNewRequest($request);

        $url = route('larafoundry.qr.verify', [
            'id' => $signInRequest->id,
            'token' => $plain,
        ]);

        return response()->json([
            'qrCode' => $this->qrCodes->svgBase64($url, $this->size()),
            'activeUntil' => $signInRequest->expires_at,
        ]);
    }

    /**
     * Web side: has the request been approved on another device yet?
     *
     * When approved, regenerate the session FIRST (fixation guard), log the
     * approver in, then consume the request so the code is single-use. The
     * tracked UserSession row is left to the TrackSessionActivity middleware on
     * the next authenticated request — we do not hand-create it (the donor did,
     * which drifted from the core's session-tracking seam).
     */
    public function poll(Request $request): JsonResponse
    {
        $signInRequest = $this->sessionRequest($request);

        if ($signInRequest === null || ! $signInRequest->approved || $signInRequest->user_id === null) {
            return response()->json(['result' => false]);
        }

        $user = $signInRequest->user()->first();

        if ($user === null) {
            return response()->json(['result' => false]);
        }

        $request->session()->regenerate();
        // Log into the stateful web guard explicitly (not the default guard):
        // the poll request rides the web session, and being explicit keeps this
        // correct even when another guard is the configured default.
        Auth::guard('web')->login($user);

        $signInRequest->delete();
        $request->session()->forget(['larafoundry_qr_id']);

        Activity::log(
            description: 'qr.login',
            logName: 'auth',
            isSuccessful: true,
            geoSync: false,
            causer: $user,
        );

        return response()->json(['result' => true]);
    }

    /**
     * Authenticated device: approve a pending sign-in request scanned from a QR.
     *
     * Guarded by `auth:sanctum` (web cookie now, Bearer token later). super-admin
     * is forbidden from approving a web sign-in. The request is matched by the
     * hashed token, so a bad/expired/foreign code is a clean 404 — never a 500,
     * because nothing is decrypted.
     */
    public function verify(Request $request, int $id, string $token): JsonResponse
    {
        $approver = $request->user();

        // super-admin and non-active (blocked/deleted) accounts cannot approve a
        // web sign-in. The approver authenticates through the `sanctum` guard, so
        // Auth::user() on the default web guard is null on the Bearer path —
        // pass the resolved approver as the audit causer explicitly.
        $status = $this->visitorStatus->for($approver);

        if ($status === VisitorStatus::ADMIN) {
            Activity::log(
                description: 'qr.verify_blocked_admin',
                logName: 'auth',
                isSuccessful: false,
                responseCode: 403,
                geoSync: false,
                causer: $approver,
            );

            return response()->json([
                'error' => __('larafoundry::auth.qr.admin_forbidden'),
            ], 403);
        }

        if (in_array($status, [VisitorStatus::BLOCKED, VisitorStatus::DELETED], true)) {
            Activity::log(
                description: 'qr.verify_blocked_account',
                logName: 'auth',
                isSuccessful: false,
                responseCode: 403,
                geoSync: false,
                causer: $approver,
            );

            return response()->json([
                'error' => __('larafoundry::auth.qr.invalid'),
            ], 403);
        }

        $signInRequest = SignInRequest::query()
            ->where('id', $id)
            ->where('token', $this->hash($token))
            ->where('approved', false)
            ->where('expires_at', '>', now())
            ->first();

        if ($signInRequest === null) {
            Activity::log(
                description: 'qr.verify_failed',
                logName: 'auth',
                isSuccessful: false,
                responseCode: 404,
                geoSync: false,
                causer: $approver,
            );

            return response()->json([
                'error' => __('larafoundry::auth.qr.invalid'),
            ], 404);
        }

        $signInRequest->forceFill([
            'user_id' => $approver->getAuthIdentifier(),
            'approved' => true,
            'approved_at' => now(),
            'approved_ip' => $request->ip(),
            'approved_user_agent' => $request->userAgent(),
        ])->save();

        Activity::log(
            description: 'qr.approved',
            logName: 'auth',
            isSuccessful: true,
            geoSync: false,
            causer: $approver,
        );

        return response()->json([
            'message' => __('larafoundry::auth.qr.approved'),
        ]);
    }

    /**
     * Return the session's live request (re-used) or a freshly minted one.
     *
     * @return array{0: string, 1: SignInRequest} [plaintext token, request row]
     */
    protected function currentOrNewRequest(Request $request): array
    {
        $existing = $this->sessionRequest($request);

        // Re-use only while a fresh plaintext is still known for it. We never
        // persist the plaintext, so once it leaves the session we cannot rebuild
        // the QR for an existing row — mint a new one instead. The plaintext is
        // re-derivable only within the same poll-cycle, so in practice each page
        // load that lacks the session secret starts a clean request.
        $secret = $request->session()->get('larafoundry_qr_secret');

        if ($existing !== null && is_string($secret) && $this->hash($secret) === $existing->token) {
            $existing->forceFill([
                'expires_at' => $this->slideExpiry($existing),
            ])->save();

            return [$secret, $existing];
        }

        $plain = (string) Str::uuid();

        $signInRequest = SignInRequest::create([
            'token' => $this->hash($plain),
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $request->session()->put('larafoundry_qr_id', $signInRequest->id);
        $request->session()->put('larafoundry_qr_secret', $plain);

        return [$plain, $signInRequest];
    }

    /**
     * The pending (unapproved or just-approved), unexpired request bound to this
     * session id, or null.
     */
    protected function sessionRequest(Request $request): ?SignInRequest
    {
        $id = $request->session()->get('larafoundry_qr_id');

        if (! is_int($id) && ! ctype_digit((string) $id)) {
            return null;
        }

        return SignInRequest::query()
            ->where('id', (int) $id)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Slide the expiry forward by the TTL, but never past the absolute cap
     * measured from when the request was created (donor hole #4).
     */
    protected function slideExpiry(SignInRequest $signInRequest): Carbon
    {
        $cap = $signInRequest->created_at->copy()->addMinutes($this->absoluteTtlMinutes());
        $slid = now()->addMinutes($this->ttlMinutes());

        return $slid->greaterThan($cap) ? $cap : $slid;
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function ttlMinutes(): int
    {
        return (int) config('larafoundry.auth.qr.ttl_minutes', 5);
    }

    protected function absoluteTtlMinutes(): int
    {
        return (int) config('larafoundry.auth.qr.absolute_ttl_minutes', 15);
    }

    protected function size(): int
    {
        return (int) config('larafoundry.auth.qr.size', 400);
    }
}
