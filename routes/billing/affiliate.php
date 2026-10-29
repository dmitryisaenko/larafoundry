<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Http\Controllers\ReferralCaptureController;
use Illuminate\Support\Facades\Route;

/*
| The add-on's public referral-capture route (Ф4.4). Loaded by the provider with the
| configured prefix + middleware (web + throttle) only when the affiliate program AND the
| capture route are enabled. Public on purpose — a visitor follows a partner's link before
| they have an account. The name stays `affiliate.capture` regardless of the URL prefix.
*/

Route::get('{code}', ReferralCaptureController::class)->name('affiliate.capture');
