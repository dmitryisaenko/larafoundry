# Multilanguage Module

## Overview

LaraFoundry's Multilanguage module provides a complete internationalization system for Laravel + Inertia + Vue SaaS applications. It handles automatic language detection, translation file management, frontend i18n integration, and a pluggable content translation API.

## Features

- 5-step automatic locale detection chain
- Separate flows for authenticated users and guests
- IP-based geolocation for first-visit language detection
- JSON + PHP array translation files
- vue-i18n v3 integration with global `t()` function
- Translations shared via Inertia props (zero extra API calls)
- Language switcher components (auth + guest layouts)
- Pluggable translation API (DeepL + Google Translate)
- REST endpoints for content translation
- Comprehensive Pest test suite

## Supported Languages

| Language | Code | Status |
|----------|------|--------|
| English | `en` | Active (default) |
| Ukrainian | `uk` | Active |
| Polish | `pl` | Ready (commented) |
| German | `de` | Ready (commented) |

Adding a new language requires:
1. Add to `available_languages` in `config/app.php`
2. Create `lang/{code}.json` translation file
3. Optionally create `lang/{code}/*.php` for framework strings
4. Add flag icon at `public/icons/flag-{code}-icon.png`

## Architecture

### Locale Detection Chain

```
Request
  |
  v
SetLocale Middleware
  |-- 1. User's locale field (DB) [auth only]
  |-- 2. Session locale
  |-- 3. Browser Accept-Language header
  |-- 4. IP geolocation (ip-api.com -> country -> locale)
  |-- 5. Default locale (config)
  |
  v
app()->setLocale() + persist to session/cookie/DB
  |
  v
HandleInertiaRequests
  |-- Load lang/{locale}.json
  |-- Load lang/{locale}/*.php
  |-- Share as Inertia props: { locale, translations }
  |
  v
Vue app.js
  |-- createI18n({ locale, messages })
  |-- Global t() function registered
  |
  v
Any Vue component: {{ t('Dashboard') }}
```

### Persistence Strategy

| User type | Primary storage | Secondary | Tertiary |
|-----------|----------------|-----------|----------|
| Authenticated | `users.locale` (DB) | Session | Cookie (1 year) |
| Guest | Cookie (10 years) | Session | - |

### File Structure

```
app/
  Http/
    Middleware/
      SetLocale.php              # Main locale detection middleware
      SetGuestLocale.php         # Guest-specific detection
      LanguageMiddleware.php     # Simple session-based locale setter
      HandleInertiaRequests.php  # Shares translations as props
    Controllers/
      LanguageController.php     # Manual language switching
      TranslationController.php  # Translation API endpoints
  Services/
    AuthUserDataService.php      # Current locale helpers
    LayoutDataService.php        # Available languages for UI
    Translators/
      DeepLTranslator.php        # DeepL API implementation
      GoogleTranslator.php       # Google Translate implementation
      GoogleTranslatorSimple.php # Simplified Google implementation
  Contracts/
    Translator.php               # Translation service interface
  Providers/
    TranslationServiceProvider.php

config/
  app.php                        # Language config, locale maps

lang/
  en.json                        # Empty (English keys = strings)
  uk.json                        # 1700+ Ukrainian translations
  pl.json                        # Polish translations
  de.json                        # German translations
  en/
    auth.php, validation.php, pagination.php, passwords.php
  uk/
    auth.php, validation.php, ...

resources/js/
  app.js                         # vue-i18n initialization
  layouts/app/
    AppPulloutMenuChangeLanguageLayout.vue       # Auth language switcher
    AppGuestPulloutMenuChangeLanguageLayout.vue  # Guest language switcher
```

### Configuration

```php
// config/app.php

'available_languages' => [
    'en' => 'English',
    'uk' => 'Українська',
    // 'pl' => 'Polski',
    // 'de' => 'Deutsch',
],

'browser_locale_map' => [
    'en' => 'en',
    'uk' => 'uk',
    'ru' => 'uk',
],

'country_locale_map' => [
    'US' => 'en', 'GB' => 'en',
    'UA' => 'uk', 'RU' => 'uk',
],

'translator_default' => env('TRANSLATION_SERVICE', 'deepl'),
```

### Translation API

The module includes a pluggable translation service for user-generated content:

```php
interface Translator
{
    public function translateText(string $text, string $from, array $to): array;
    public function getSupportedLanguages(): array;
    public function isLanguageSupported(string $code): bool;
    public function getUsageInfo(): ?array;
}
```

REST endpoints:
- `POST /translate` - Translate text between languages
- `GET /translate/usage` - Check API quota
- `GET /translate/languages` - List supported languages

### Frontend Usage

```vue
<template>
    <h1>{{ t('Dashboard') }}</h1>
    <p>{{ t('Welcome back') }}</p>
</template>
```

No imports needed. The `t()` function is registered globally via `app.config.globalProperties`.

### Database

The `users` table includes a `locale` column:

```php
$table->string('locale')->nullable()->default('en');
```

### Routes

```php
Route::get('/language_switch/{locale?}', [LanguageController::class, 'languageSwitch'])
    ->name('language_switch');
```

## Testing

Full Pest test coverage:
- Locale detection for all 5 fallback levels
- Language switching (auth + guest)
- Invalid locale rejection
- Translation loading and merging
- Cookie and session persistence
- Mocked translation API responses
