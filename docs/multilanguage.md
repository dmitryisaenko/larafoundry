# Multilanguage / i18n

The localization layer resolves an active locale for every request, applies it
inline (no redirect, no page reload), and ships the matching translation bag to
the Vue frontend through Inertia. One validated allow-list is the single source
of truth: every locale that arrives from the outside - a stored user preference,
a session, a cookie, the browser header, an optional geo lookup - is checked
against it before it is applied, so junk codes such as `ua` or `English` never
reach the session, cookie, or database. English and Ukrainian ship in the box; a
host adds languages or overrides strings with its own lang files.

This is the current, accurate reference for the shipped package. An older
planning draft lives at [modules/multilanguage.md](modules/multilanguage.md); it
predates the build and describes things that were never shipped - a DeepL /
Google Translate machine-translation API, a `TranslationController`, a
`/translate` endpoint, and locale config in `config/app.php`. Ignore it for the
real behaviour (see [What changed](#what-changed-from-the-early-draft)).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)
- [What changed from the early draft](#what-changed-from-the-early-draft)

## Install

Localization ships with the core package; there is nothing extra to require. The
host wires it in by:

1. Appending `SetLocale` to the `web` middleware group, before
   `HandleInertiaRequests` so the resolved locale is applied before the
   translation bag is built.
2. Implementing `HasLocalePreference` on its `User` model (optional; only needed
   if the host stores a per-user locale column).

`SetLocale` is referenced by class, not by an alias - the core does not register
a middleware alias for it.

```php
// bootstrap/app.php
use Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale;
use App\Http\Middleware\HandleInertiaRequests; // your subclass of the core one

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        SetLocale::class,
        HandleInertiaRequests::class,
        // ...
    ]);
})
```

```php
// app/Models/User.php
use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;

class User extends Authenticatable implements HasLocalePreference
{
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    public function setPreferredLocale(string $locale): void
    {
        $this->forceFill(['locale' => $locale])->save();
    }
}
```

If the host stores a locale, add a nullable `locale` column to its `users`
table. The core reads and writes it only through the `HasLocalePreference`
contract, so it never assumes the column exists.

## Configuration

All localization settings live under the `locale` key of
`config/larafoundry.php`. A locale is one ISO 639-1 code (`en`, `uk`, `de`) used
everywhere - URL, cookie, database, translations - with no parallel `ua` /
`English` keys.

```php
'locale' => [
    'available' => ['en', 'uk'],
    'default' => env('LARAFOUNDRY_LOCALE', 'en'),
    'cookie' => 'locale',
    'locales' => [
        'en' => ['native' => 'English', 'flag' => '🇬🇧'],
        'uk' => ['native' => 'Українська', 'flag' => '🇺🇦'],
    ],
    'detect_map' => [
        // 'ru' => 'uk',
    ],
    'geoip' => [
        'enabled' => false,
        'resolver' => null,
    ],
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `available` | `['en', 'uk']` | The allow-list. The only list everything validates against - a code not in it is discarded, never applied. |
| `default` | `en` | The fallback locale when nothing else resolves. Must itself be in `available`. |
| `cookie` | `locale` | Name of the cookie a guest's choice is stored in (a year-long cookie). |
| `locales` | `en`, `uk` metadata | Per-locale display metadata (`native` name and `flag`) for the switcher. A code with no metadata falls back to the bare code in the switcher, never hidden. |
| `detect_map` | empty | Browser-detection exceptions ONLY: a non-identical mapping such as a Russian-language browser to a Ukrainian interface (`ru => uk`). A browser code that already matches an available locale is taken as-is and need not be listed. |
| `geoip.enabled` | `false` | Master switch for the optional IP geo-resolver. Off by default: a synchronous external call on every request is slow, leaks the IP, and is a point of failure. |
| `geoip.resolver` | `null` | Class name of a host-supplied `LocaleGeoResolver`. The country-to-locale mapping is that resolver's job, not the core's. |

## Usage

### The locale resolution chain

`SetLocale` picks the active locale from the first candidate that passes the
allow-list, in this order:

1. The authenticated user's stored preference (via `HasLocalePreference`) - authoritative, no redirect.
2. The session (`locale` key).
3. The cookie (the configured `cookie` name).
4. The `Accept-Language` header: the two-letter browser code is taken as-is when it is an available locale, otherwise mapped through `detect_map`.
5. The optional geo-IP resolver (only when `geoip.enabled` is true and a resolver is bound).
6. The configured `default`.

Every candidate is validated before use, and there is a last-resort guard: even
if the configured `default` is itself misconfigured, the middleware falls back to
the first available locale rather than applying something off the list. The
chosen locale is applied inline with `App::setLocale()` - there is no redirect
and no client-side reload. The choice is persisted (always to the session, plus a
year-long cookie when it was freshly detected) so the next request is stable. For
an authenticated user whose stored preference differs from the resolved locale,
the middleware writes it back through `setPreferredLocale()`.

### Switching language

`LanguageController::switch()` records an explicit choice. It is a
`POST larafoundry/language` route (named `larafoundry.language.switch`), in the
`web` group, so it is CSRF-protected and works for guests and authenticated users
alike. The controller only persists the choice; `SetLocale` reads it back and
applies it on the next request.

- Guests: written to the session and a year-long cookie.
- Authenticated users: the above plus the stored DB preference. Persisting the DB
  value is what stops the old preference (authoritative in `SetLocale`, step 1)
  from bouncing the choice back on the very next request.

The submitted locale is validated with the `ValidLocale` rule, so no untrusted
string reaches the session, cookie, or database.

### The language switcher on the frontend

The core's `HandleInertiaRequests` shares `available_locales` on every response:
a list of `{code, native, flag}` built from the `available` allow-list paired
with its `locales` metadata. The frontend renders the switcher dropdown from this
shared list and posts the chosen code to `larafoundry.language.switch`. It also
shares `locale` (the active code) and `translations` (the bag below), which the
Vue entrypoint reads to boot vue-i18n.

### The layered translation loader

For the active locale, `HandleInertiaRequests::translations()` merges three
layers, later layers overriding earlier ones:

1. The core's bundled frontend dictionary, `lang/frontend/{locale}.json`, shipped
   inside the package for the locales the core supports (`en`, `uk`). English
   maps each key to its own English text; Ukrainian carries the translations.
2. The host's `lang/{locale}.json` (in the app's base path) - overrides core
   strings and adds the host's own.
3. The host's `lang/{locale}/*.php` group files, each loaded under its group key,
   matching Laravel's own loader.

So a host can override any core string (for example
`Employees` to a house term) just by supplying its own `lang/uk.json`, and core
strings it does not touch stay in place. The bundled core dictionary is memoized
per locale per request, since it is immutable for the life of a deploy.

### Adding a language

1. Add the ISO 639-1 code to `locale.available` (and give it `locale.locales`
   metadata so the switcher shows a name and flag).
2. Ship a `lang/{code}.json` in the host for the frontend strings, and
   `lang/{code}/*.php` group files for backend strings. The core only bundles
   frontend dictionaries for `en` and `uk`; for any other code the host supplies
   everything.

## API reference

### `SetLocale` middleware

Resolves and applies the active locale (the six-step chain above). Referenced by
class in the host's `web` group; the core registers no alias for it. Config root:
`larafoundry.locale`.

### `LanguageController`

| Method | Route | Purpose |
|--------|-------|---------|
| `switch(Request)` | `POST larafoundry/language` (`larafoundry.language.switch`) | Validate and persist an explicit locale choice; redirect back to a same-host URL. |

### `ValidLocale` rule

`Dmitryisaenko\LaraFoundry\Rules\ValidLocale` - a `ValidationRule` that passes
only when the value is a string in `larafoundry.locale.available`. Use it on any
entry point where a locale arrives from outside.

### `HasLocalePreference` contract

Implemented by the host `User` model when it stores a locale column:

- `preferredLocale(): ?string` - the stored locale, or null.
- `setPreferredLocale(string $locale): void` - persist a resolved locale.

### `LocaleGeoResolver` contract

`resolve(string $ip, array $available): ?string` - best-effort locale for an IP,
or null. The core ships no implementation; a host binds its own and enables it via
`larafoundry.locale.geoip`.

### Shared Inertia props (from `HandleInertiaRequests`)

- `locale` - the active locale code (`App::getLocale()`).
- `available_locales` - the switcher list, each entry `{code, native, flag}`.
- `translations` - the merged translation bag for the active locale.

## Security notes

- **One allow-list, checked at every entry.** `ValidLocale` and `SetLocale`'s
  `isAllowed()` both gate on `larafoundry.locale.available`. A submitted or
  detected code outside it is rejected or discarded, so junk like `ua` never
  reaches the session, cookie, or database. Even a misconfigured `default` cannot
  apply an off-list locale - the middleware falls back to the first available
  code.
- **The switch is CSRF-protected.** The switch route is a `POST` in the `web`
  group, so Laravel's CSRF token is required; a locale change cannot be forced by
  a cross-site `GET`.
- **The return redirect cannot become an open redirect.** After a switch,
  `LanguageController::safeReturnUrl()` constrains the destination to the
  application's own host. `URL::previous()` falls back to the attacker-controlled
  `Referer` header when the session has no stored previous URL, so a forged
  `Referer` pointing off-host is rejected and the user is sent to the app root
  instead. The protection is the host check, not `back()` ignoring the `Referer`.
- **Geo-IP is opt-in.** The synchronous external lookup that leaks the client IP
  is off by default and lives behind the host-supplied `LocaleGeoResolver`
  contract, not baked into the request path.
- **No client-side reload injection.** The locale takes effect on the same
  request via `App::setLocale()`; the core does not inject a
  `window.location.reload()` script into the response body.

## Testing

The localization suite lives in `tests/Feature/Localization/`:

- `LanguageControllerTest`: guest session + cookie persistence, DB persistence
  for authenticated users, the no-bounce-back guarantee, whitelist rejection of a
  junk locale (`ua`), non-string and missing-locale rejection, and the
  open-redirect guard against a forged `Referer`.
- `TranslationBagTest`: the bundled Ukrainian dictionary shipping out of the box,
  the English key-as-text fallback, a host `lang/uk.json` overriding a core
  string while untouched core strings remain, and the `available_locales` list
  (metadata, bare-code fallback, and dropping a non-string entry rather than
  fatalling the shared prop).
- `I18nParityTest`: every dot-key present in the default locale (`en`) exists in
  each other configured locale and vice versa (no drift, no orphans), and no
  locale has blank values.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older [modules/multilanguage.md](modules/multilanguage.md),
the shipped package is narrower and differs in names and location:

| Early draft | Shipped |
|-------------|---------|
| Machine-translation API (DeepL / Google Translate), a `Translator` contract, `DeepLTranslator` / `GoogleTranslator` services | Not built. No content-translation service ships. |
| `TranslationController` with `POST /translate`, `GET /translate/usage`, `GET /translate/languages` | Not built. The only route is the language switch. |
| Locale config in `config/app.php` (`available_languages`, `browser_locale_map`, `country_locale_map`) | `config/larafoundry.php` under `locale` (`available`, `detect_map`, `locales`, `geoip`). |
| `GET /language_switch/{locale?}` | `POST larafoundry/language` (`larafoundry.language.switch`), CSRF-protected. |
| Separate `SetLocale` + `SetGuestLocale` + `LanguageMiddleware` | One `SetLocale` for guests and authenticated users alike. |
| IP geolocation always on (`ip-api.com` on every guest request) | Opt-in via the `LocaleGeoResolver` contract; off by default. |
| Response-injected `window.location.reload()` after a switch | Applied inline on the same request; no reload. |
| Polish / German shipped-and-commented, flag icons in `public/icons/` | English and Ukrainian ship; other languages are added by the host with its own lang files, flag as an emoji in `locale.locales`. |
