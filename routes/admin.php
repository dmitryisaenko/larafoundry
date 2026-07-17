<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\AdminOtpChallengeController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\AffiliateController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\CompanyController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\DashboardController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ImpersonateController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\PaymentController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ProfileController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\PromoController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\SecurityController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\UserController;
use Dmitryisaenko\LaraFoundry\Email\Http\Controllers\Admin\EmailTemplateController;
use Dmitryisaenko\LaraFoundry\Legal\Http\Controllers\Admin\LegalPageController;
use Dmitryisaenko\LaraFoundry\Notifications\Http\Controllers\Admin\BroadcastNotificationController;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Controllers\Admin\TicketController as AdminTicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry operator-console routes (super-admin only)
|--------------------------------------------------------------------------
| Phase 2.3: user management + impersonation, on top of the activity log
| (phase 2.1, `admin.activity-log.*`). Phase 3.3 adds company management
| (`admin.companies.*`). Everything is behind `larafoundry.admin` (super-admin
| via VisitorStatus) on an authenticated, verified session.
|
| `impersonate.leave` is the one exception: it lives OUTSIDE the admin gate,
| because while impersonating the actor IS the target user (not an admin), so
| the gate would lock them out of their own exit. It only needs auth.
*/

Route::middleware(['web', 'auth', 'verified', 'larafoundry.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // OTP step-up challenge (phase 1.4). Deliberately OUTSIDE the
        // `larafoundry.admin.otp` gate below — the gate redirects here, so
        // gating this route too would loop. The POST is rate-limited like
        // Fortify's own two-factor challenge.
        Route::get('otp', [AdminOtpChallengeController::class, 'show'])->name('otp.show');
        Route::post('otp', [AdminOtpChallengeController::class, 'verify'])
            ->middleware('throttle:6,1')
            ->name('otp.verify');

        // Operator self-service security — ENROLMENT surface, deliberately OUTSIDE
        // the `larafoundry.admin.otp` gate below. The gate (and the OTP challenge)
        // redirect an un-enrolled operator here via `two_factor_setup_route`, so
        // gating this would loop (same reason as otp.show). Only the actions the
        // chicken-and-egg needs live here: view the page, enable + confirm 2FA, and
        // change the password (already defended by `current_password`, so a stolen
        // session cannot silently swap the credential). The QR + recovery codes are
        // NOT re-fetchable endpoints — the page reads them from `show()` props only
        // while enrolment is in progress (unconfirmed) or for a stepped-up session,
        // so a non-stepped-up session cannot read the live TOTP secret or recovery
        // codes (which would otherwise satisfy the step-up challenge). Everything is
        // behind `larafoundry.admin` (super-admin only) and proxies the core's /
        // Fortify's own actions (no dependence on Fortify's unnamed /user/* routes).
        // The operator's own profile hub — self-service account screens (profile /
        // photo / security / sessions / preferences), exposed under `admin.*` so
        // the confine allows them (the operator is locked out of the tenant
        // `/profile`, `/user`, `/settings`, `/auth/sessions` routes). Deliberately
        // OUTSIDE the OTP step-up gate for the same reason as `security.show`: the
        // hub's Security tab IS the 2FA enrolment surface, so gating the page would
        // loop an un-enrolled operator. Each write delegates to the tenant
        // self-service controller acting on the operator (no logic duplicated); the
        // destructive 2FA actions stay behind the gate on `security.two-factor.*`.
        // The EMAIL is not editable here — it is the reserved operator identity.
        Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('profile/information', [ProfileController::class, 'updateInformation'])
            ->middleware('throttle:20,1')
            ->name('profile.information.update');
        Route::post('profile/avatar', [ProfileController::class, 'updateAvatar'])
            ->middleware('throttle:20,1')
            ->name('profile.avatar.update');
        Route::delete('profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
        // 'others' before '{session}' so the literal segment wins the match.
        Route::delete('profile/sessions/others', [ProfileController::class, 'destroyOtherSessions'])
            ->name('profile.sessions.destroy-others');
        Route::delete('profile/sessions/{session}', [ProfileController::class, 'destroySession'])
            ->name('profile.sessions.destroy');

        // The old standalone operator-security page is now the hub's Security tab;
        // keep its route name (the OTP gate's `two_factor_setup_route` points at it)
        // as a redirect into the hub, so bookmarks and the enrolment redirect land
        // on the right tab. reflash() carries any flash the gate set (e.g. the
        // "set up 2FA" nudge) across this extra hop so the hub still shows it.
        Route::get('security', function () {
            session()->reflash();

            return redirect()->route('admin.profile.show', ['tab' => 'security']);
        })->name('security.show');
        Route::post('security/two-factor/enable', [SecurityController::class, 'enableTwoFactor'])
            ->middleware('throttle:6,1')
            ->name('security.two-factor.enable');
        Route::post('security/two-factor/confirm', [SecurityController::class, 'confirmTwoFactor'])
            ->middleware('throttle:6,1')
            ->name('security.two-factor.confirm');
        Route::put('security/password', [SecurityController::class, 'updatePassword'])
            ->middleware('throttle:6,1')
            ->name('security.password.update');

        // The console proper: everything past the OTP step-up gate.
        Route::middleware('larafoundry.admin.otp')->group(function () {
            // The console landing screen (phase 3.4): URL /admin.
            Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');

            // Destructive 2FA management lives INSIDE the step-up gate (unlike the
            // enrolment actions above): disabling 2FA or rotating recovery codes
            // only matters once the operator is enrolled, and by then they can
            // pass the step-up — so requiring a stepped-up session here closes the
            // stolen-cookie path (a cookie without a fresh OTP cannot strip the
            // second factor or read/rotate its recovery codes).
            Route::delete('security/two-factor', [SecurityController::class, 'disableTwoFactor'])
                ->name('security.two-factor.disable');
            Route::post('security/two-factor/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes'])
                ->middleware('throttle:6,1')
                ->name('security.two-factor.recovery-codes.regenerate');

            Route::prefix('users')->name('users.')->group(function () {
                Route::get('/', [UserController::class, 'index'])->name('index');
                Route::get('search', [UserController::class, 'search'])->name('search');
                Route::get('create', [UserController::class, 'create'])->name('create');
                Route::post('/', [UserController::class, 'store'])->name('store');
                Route::get('{user}/edit', [UserController::class, 'edit'])->name('edit');
                Route::put('{user}', [UserController::class, 'update'])->name('update');
                Route::post('{user}/block', [UserController::class, 'block'])->name('block');
                Route::post('{user}/unblock', [UserController::class, 'unblock'])->name('unblock');
                Route::post('{user}/verify-email', [UserController::class, 'verifyEmail'])->name('verify-email');
                Route::post('{user}/unverify-email', [UserController::class, 'unverifyEmail'])->name('unverify-email');
                Route::post('{user}/verify-phone', [UserController::class, 'verifyPhone'])->name('verify-phone');
                Route::post('{user}/unverify-phone', [UserController::class, 'unverifyPhone'])->name('unverify-phone');
                Route::delete('{user}', [UserController::class, 'destroy'])->name('destroy');
                Route::post('{user}/restore', [UserController::class, 'undelete'])->name('restore');
            });

            Route::prefix('companies')->name('companies.')->group(function () {
                Route::get('/', [CompanyController::class, 'index'])->name('index');
                Route::get('search', [CompanyController::class, 'search'])->name('search');
                Route::get('{company}', [CompanyController::class, 'show'])->name('show');
                Route::post('{company}/block', [CompanyController::class, 'block'])->name('block');
                Route::post('{company}/unblock', [CompanyController::class, 'unblock'])->name('unblock');
            });

            // Broadcasts (phase 4.1): draft → send (queued fan-out) → manage.
            // `send` is throttled so a held submit cannot queue repeated fan-outs.
            Route::prefix('notifications')->name('notifications.')->group(function () {
                Route::get('/', [BroadcastNotificationController::class, 'index'])->name('index');
                Route::get('create', [BroadcastNotificationController::class, 'create'])->name('create');
                Route::post('/', [BroadcastNotificationController::class, 'store'])->name('store');
                Route::get('{notification}/edit', [BroadcastNotificationController::class, 'edit'])->name('edit');
                Route::put('{notification}', [BroadcastNotificationController::class, 'update'])->name('update');
                Route::post('{notification}/send', [BroadcastNotificationController::class, 'send'])
                    ->middleware('throttle:10,1')
                    ->name('send');
                Route::delete('{notification}', [BroadcastNotificationController::class, 'destroy'])->name('destroy');
            });

            // Helpdesk (phase 4.2): the operator queue + one ticket's thread and
            // its lifecycle (reply / close / status / priority / category /
            // label). The user side lives in routes/tickets.php; this is the
            // operator console, behind the super-admin gate.
            Route::prefix('tickets')->name('tickets.')->group(function () {
                Route::get('/', [AdminTicketController::class, 'index'])->name('index');
                Route::get('create', [AdminTicketController::class, 'create'])->name('create');
                Route::post('/', [AdminTicketController::class, 'store'])->name('store');
                Route::get('{ticket}', [AdminTicketController::class, 'show'])->name('show');
                Route::get('{ticket}/edit', [AdminTicketController::class, 'edit'])->name('edit');
                Route::put('{ticket}', [AdminTicketController::class, 'update'])->name('update');
                Route::post('{ticket}/reply', [AdminTicketController::class, 'reply'])->name('reply');
                Route::patch('{ticket}/close', [AdminTicketController::class, 'close'])->name('close');
                Route::patch('{ticket}/priority', [AdminTicketController::class, 'updatePriority'])->name('priority');
                Route::post('{ticket}/category', [AdminTicketController::class, 'toggleCategory'])->name('category');
                Route::post('{ticket}/label', [AdminTicketController::class, 'toggleLabel'])->name('label');
            });

            // Monetization stubs (phase 4): Payments over the billing seam, plus
            // Affiliates and Promo codes. Each reserves its route/menu slot and
            // shows an empty state with an upsell CTA until the paid billing
            // add-on is installed (it ships its own richer console for each).
            Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
            Route::get('affiliates', [AffiliateController::class, 'index'])->name('affiliates.index');
            Route::get('promo', [PromoController::class, 'index'])->name('promo.index');

            // NOTE: the app-scope Settings screen (admin.settings.*) was removed —
            // its keys (support_email / signups_enabled) are reserved config with
            // no consumer yet, so there is nothing to edit. Re-add the route +
            // Settings/Http/Controllers/Admin/SettingsController when a consumer
            // lands (see config/larafoundry.php `settings`).

            // Email templates (phase 5.1, two layers in phase 2b). TRANSACTIONAL
            // codes (registry) are edit-only + fail-closed; MARKETING templates
            // (self-contained DB rows) get full CRUD. The `{code}` is a slug (not a
            // model binding) — a transactional template has a DB row only once
            // edited, so the controller resolves it from the registry ∪ DB and
            // 404s an unknown code. `destroy` also 403s a transactional code.
            Route::prefix('email-templates')->name('email-templates.')->group(function () {
                Route::get('/', [EmailTemplateController::class, 'index'])->name('index');
                // Marketing create + the code-less draft preview must precede the
                // `{code}` routes so the literal segments win the match.
                Route::get('create', [EmailTemplateController::class, 'create'])->name('create');
                Route::post('/', [EmailTemplateController::class, 'store'])
                    ->middleware('throttle:30,1')
                    ->name('store');
                Route::post('preview-draft', [EmailTemplateController::class, 'previewDraft'])->name('preview-draft');
                Route::get('{code}/edit', [EmailTemplateController::class, 'edit'])->name('edit');
                Route::put('{code}', [EmailTemplateController::class, 'update'])->name('update');
                Route::delete('{code}', [EmailTemplateController::class, 'destroy'])->name('destroy');
                Route::post('{code}/duplicate', [EmailTemplateController::class, 'duplicate'])
                    ->middleware('throttle:30,1')
                    ->name('duplicate');
                Route::post('{code}/preview', [EmailTemplateController::class, 'preview'])->name('preview');
                Route::post('{code}/test', [EmailTemplateController::class, 'sendTest'])
                    ->middleware('throttle:'.config('larafoundry-email.test_email.throttle', '5,1'))
                    ->name('test');
            });

            // Legal pages (phase 5.3): edit-only over the shipped pages (Terms,
            // Privacy, Cookie policy), with a server-rendered, sanitized preview.
            // The `{slug}` is a registry key (not a model binding) — a page has a
            // DB row only once published, so the controller resolves it from the
            // registry and 404s an unknown slug. The public pages are in
            // routes/legal.php (open to everyone); this is the operator editor.
            Route::prefix('legal-pages')->name('legal-pages.')->group(function () {
                Route::get('/', [LegalPageController::class, 'index'])->name('index');
                Route::get('{slug}/edit', [LegalPageController::class, 'edit'])->name('edit');
                Route::put('{slug}', [LegalPageController::class, 'update'])->name('update');
                Route::post('{slug}/preview', [LegalPageController::class, 'preview'])->name('preview');
            });

            Route::post('impersonate/{user}', [ImpersonateController::class, 'take'])
                ->name('impersonate.take');
        });
    });

// Leaving impersonation runs as the impersonated user — outside the admin gate.
Route::middleware(['web', 'auth'])
    ->post('impersonate/leave', [ImpersonateController::class, 'leave'])
    ->name('impersonate.leave');
