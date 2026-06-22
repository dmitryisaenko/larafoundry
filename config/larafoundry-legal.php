<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry legal pages (phase 5.3)
|--------------------------------------------------------------------------
| Registry of editable legal pages (Terms / Privacy / Cookie policy). The SINGLE
| source of truth (like the larafoundry-email.templates registry): ONLY the slugs
| registered here are editable (fail-closed) — the super-admin cannot create an
| arbitrary slug, and the public page `/legal/{slug}` is served only for a
| registered slug.
|
| Data flow:
|   the registry (this file) — PLACEHOLDER DEFAULTS: title / body_html per locale.
|   larafoundry_legal_pages (DB) — ONLY the page published by the super-admin.
|   No DB row (or not published) → the public page 404s (a placeholder is NEVER
|   served as real legal text — the project owner edits and publishes it first
|   under their own jurisdiction).
|
| Adding a new legal page = +1 entry here (slug + default text). The UI editor
| picks it up automatically.
|
| body_html is sanitised by the email-friendly HTML purifier (the shared
| HtmlSanitizer) on write AND on output. There is NO variable rendering — the
| pages are static (unlike emails), which removes that class of vulnerability.
|
| Legal-page content is host-specific (jurisdiction, lawyer's text); the core
| provides only the mechanism + placeholders. The `version` is bumped by the
| owner on a material change and feeds the re-accept gate (phase 5.3 part B).
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Cookie consent (phase 5.3 part B)
    |--------------------------------------------------------------------------
    | OFF by default: the core sets only strictly-necessary cookies (session /
    | XSRF / locale / appearance), which do NOT require consent. A host turns the
    | banner on only once it adds non-essential cookies (analytics/marketing).
    | Granularity is binary (accept all / necessary only); the decision is stored
    | in an encrypted cookie for guests and synced to the `cookie_consent` user
    | setting on login (the source of truth once authenticated, decision D-5.3-6).
    |
    | SECURITY: keep `cookie` under Laravel's cookie encryption — do NOT add it to
    | EncryptCookies::$except. Encryption is what makes a guest's decision tamper-
    | resistant; exempting it would let anyone forge an `accept` cookie.
    */
    'cookie_consent' => [
        'enabled' => false,
        'cookie' => 'larafoundry_cookie_consent',
        'lifetime_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Terms acceptance + re-accept gate (phase 5.3 part B)
    |--------------------------------------------------------------------------
    | `enforce` ON requires a registration-time checkbox AND re-acceptance when
    | the published Terms version is bumped. It is FAIL-OPEN: when the `slug` page
    | is not published (currentVersion is null) nothing is enforced, so the gate
    | never locks users out of a page that does not exist yet. `slug` chooses which
    | legal page drives the gate. `except_routes` / `except_prefixes` extend the
    | built-in bypass list (logout, the accept screen, legal pages, verification).
    */
    'terms' => [
        'enforce' => true,
        'slug' => 'terms',
        'except_routes' => [],
        'except_prefixes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Account erasure (phase 5.3 part D — GDPR right to be forgotten)
    |--------------------------------------------------------------------------
    | Self-deletion is a reversible soft-delete (`user_deleted_at`) that starts a
    | grace clock; the `larafoundry:purge-deleted-accounts` command irreversibly
    | anonymises an account once it has sat soft-deleted for `grace_days` (default
    | 30), giving the user a window to change their mind (a super-admin can
    | restore them via the console during it). The host schedules the command
    | daily — the core does not own a scheduler.
    |
    | `anonymized_email_domain` is the domain anonymised emails are minted under
    | (`deleted-{id}@…`); the RFC 2606 reserved `.invalid` TLD can never be a
    | deliverable address, so an anonymised account is unreachable by mail.
    */
    'erasure' => [
        'grace_days' => 30,
        'anonymized_email_domain' => 'deleted.invalid',
    ],

    'pages' => [

        // Terms of Service.
        'terms' => [
            'title' => [
                'en' => 'Terms of Service',
                'uk' => 'Умови використання',
            ],
            'body_html' => [
                'en' => '<p>Add your Terms of Service here. Edit and publish this page in the operator console before you launch.</p>',
                'uk' => '<p>Додайте сюди свої умови використання. Відредагуйте та опублікуйте цю сторінку в консолі оператора перед запуском.</p>',
            ],
        ],

        // Privacy Policy.
        'privacy' => [
            'title' => [
                'en' => 'Privacy Policy',
                'uk' => 'Політика конфіденційності',
            ],
            'body_html' => [
                'en' => '<p>Add your Privacy Policy here. Edit and publish this page in the operator console before you launch.</p>',
                'uk' => '<p>Додайте сюди свою політику конфіденційності. Відредагуйте та опублікуйте цю сторінку в консолі оператора перед запуском.</p>',
            ],
        ],

        // Cookie Policy.
        'cookies' => [
            'title' => [
                'en' => 'Cookie Policy',
                'uk' => 'Політика щодо файлів cookie',
            ],
            'body_html' => [
                'en' => '<p>Add your Cookie Policy here. Edit and publish this page in the operator console before you launch.</p>',
                'uk' => '<p>Додайте сюди свою політику щодо файлів cookie. Відредагуйте та опублікуйте цю сторінку в консолі оператора перед запуском.</p>',
            ],
        ],

    ],

];
