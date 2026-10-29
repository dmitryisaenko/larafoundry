<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry Billing — backend translations (larafoundry:: namespace)
|--------------------------------------------------------------------------
| Server-rendered strings for the billing module: payment mail, notifications
| and any backend-formatted text. Plan names, feature labels and other UI text
| are English-as-key FRONTEND strings in lang/frontend/{locale}.json that the
| Vue layer renders with $t().
|
| A host overrides these in lang/vendor/larafoundry/{locale}/billing.php, like
| any other core translation file.
*/

return [

    'plan' => [
        // Used where a plan must be named server-side (e.g. an invoice line in
        // mail). The frontend uses the English-as-key strings instead.
        'label' => 'Plan',
    ],

    'promo' => [
        // The single generic message the validate endpoint returns on ANY failure,
        // so it never reveals which check failed (anti-enumeration oracle).
        'invalid' => 'This promo code is invalid or has expired.',
    ],

    'entitlement' => [
        // Shown (B6) when a tenant's plan does not include a gated feature.
        // Deliberately vague so it never reveals plan internals.
        'required' => 'Your current plan does not include this feature.',
    ],

    // Owner payment mail (B7a). :amount is preformatted (e.g. "25.00 USD"); :company is
    // the company name. A host overrides these by publishing the lang files.
    'payment' => [
        'success' => [
            'subject' => 'Payment received',
            'greeting' => 'Payment received',
            'line' => 'We received your payment of :amount for :company.',
            'thanks' => 'Thank you.',
        ],
        'failed' => [
            'subject' => 'Payment failed',
            'greeting' => 'Payment failed',
            'line' => 'Your payment of :amount for :company could not be processed.',
            'help' => 'Please check your payment method and try again.',
        ],
        // Flash / validation messages for the checkout flow.
        'no_company' => 'No active company.',
        'not_connected' => 'Billing is not connected. Please contact support.',
        'checkout_failed' => 'We could not start checkout. Please try again.',
    ],

    'trial' => [
        'unavailable' => 'A free month is not available for this company.',
        'activated' => 'Your free month is now active.',
    ],

    // Expiry-reminder mail (B7a). :days is the number of days left; :company is the
    // company name. Sent by the larafoundry-billing:check-expiring cron.
    'subscription' => [
        'expiring' => [
            'subject' => 'Your subscription is expiring soon',
            'greeting' => 'Subscription expiring soon',
            'line' => 'Your subscription for :company will expire in :days days.',
            'action' => 'Please renew to keep access to all features.',
        ],
    ],

    // Super-admin console flash messages (B7b).
    'admin' => [
        'promo' => [
            'created' => 'Promo code created.',
            'updated' => 'Promo code updated.',
            'activated' => 'Promo code activated.',
            'deactivated' => 'Promo code deactivated.',
        ],
        // Affiliate console flash (Ф4.4).
        'affiliate' => [
            'created' => 'Affiliate created.',
            'updated' => 'Affiliate updated.',
        ],
        'payout' => [
            'approved' => ':count commission(s) approved.',
            'paid' => ':count commission(s) marked paid.',
            'voided' => ':count commission(s) voided.',
        ],
    ],

];
