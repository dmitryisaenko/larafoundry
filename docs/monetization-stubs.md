# Monetization upsell stubs

The free core reserves three monetization screens in the operator console -
Payments, Affiliates and Promo codes - as inert **upsell placeholders**. They give a
host the full console surface from day one, and they give the paid
`larafoundry-billing` add-on a clean, named place to land. The real payments,
affiliate and promo-code functionality lives in the add-on, not here: the core ships
only the reserved slots, a config-driven upsell CTA, and the seam the add-on
overrides them through.

This page is the reference for the reserved slots. The billing seam those slots sit
next to is documented separately in [billing-seam.md](billing-seam.md); the add-on
that fills them documents its own usage in its private repository.

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [How the add-on overrides a stub](#how-the-add-on-overrides-a-stub)
- [Security notes](#security-notes)
- [Testing](#testing)

## Install

The stubs ship with the core package; there is nothing extra to require and no
migration. Each of the three screens is:

- a stub controller returning an Inertia empty-state page with two props,
  `billing_enabled` and `upsell_url`;
- a route inside the same `larafoundry.admin` + OTP-gated admin route group as the
  rest of the console;
- a nav `MenuItem` in the admin menu;
- a Vue page (mirroring the existing Payments stub) and an icon.

The reserved route names are:

| Screen | Route name |
|--------|-----------|
| Payments | `admin.payments.index` |
| Affiliates | `admin.affiliates.index` |
| Promo codes | `admin.promo.index` |

The Payments stub already existed (`v0.27.x`); this band added the upsell CTA to it
and introduced the Affiliates and Promo stubs alongside.

## Configuration

The upsell target lives under the `upsell` key of `config/larafoundry.php`:

```php
'upsell' => [
    'billing_url' => env('LARAFOUNDRY_UPSELL_BILLING_URL', 'https://larafoundry.com'),
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `billing_url` | `https://larafoundry.com` | Where the upsell CTA points. Env `LARAFOUNDRY_UPSELL_BILLING_URL`. The CTA renders only when a URL is set. |

## Usage

Out of the box the three screens render as empty states. When billing is disabled
**and** a `billing_url` is set, each shows an upsell CTA pointing at that URL - the
signpost to the paid add-on. Install the paid `larafoundry-billing` add-on and the
CTA goes quiet: the add-on rebinds the screens to their real, functional versions
(see below), so the placeholder is never shown next to working billing.

The CTA is driven entirely by the two props the stub controller ships,
`billing_enabled` and `upsell_url`; it renders only when billing is off and a URL is
present.

## How the add-on overrides a stub

No new override toggle was added to the core for this. The paid add-on reuses the
seams the package already has:

1. **Named routes.** The add-on re-points the reserved route names
   (`admin.payments.index`, `admin.affiliates.index`, `admin.promo.index`) at its
   own controllers.
2. **Vue-page-by-string.** The Inertia page is resolved by string name, so the
   add-on swaps the stub page for its real one without the core changing.
3. **Rebindable bindings.** The stub controllers and their supporting services are
   resolved from the container, so the add-on rebinds them.

Because the slots keep stable route names and page identifiers, the add-on lands on
them cleanly and the menu item, the icon and the nav position all carry over to the
real screen. This is the same override pattern the [billing seam](billing-seam.md)
uses for its contracts - a placeholder in the free core, the real thing rebound by
the paid add-on.

## Security notes

- **The stubs sit behind the full admin gate.** Each route lives inside the same
  `larafoundry.admin` + OTP-gated admin group as every other console screen, so a
  reserved placeholder is never reachable outside the operator console.
- **The stubs take no money and hold no records.** They are inert empty states; the
  core stores no payment, affiliate or promo data (that is the add-on's job), so a
  reserved slot exposes nothing.
- **The CTA URL is config-controlled.** The upsell target comes from
  `larafoundry.upsell.billing_url`, not from a request value, so the CTA cannot be
  pointed at an attacker URL through user input.

## Testing

The stub suite asserts each of the three routes is registered inside the gated admin
group, renders its empty-state page with the `billing_enabled` / `upsell_url` props,
and shows the CTA only when billing is disabled and a URL is set. Every module
passes `/security-review` + `/code-review` before its tag.

Run them with Pest:

```bash
composer test
```
