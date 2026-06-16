/**
 * Pure date-formatting helpers (no Vue / Inertia imports), so they are trivially
 * unit-testable and safe to import anywhere.
 *
 * A user's `date_format` preference (see `larafoundry.profile.ui_settings`) is
 * one of:
 *   - 'auto' — order derived from the active app locale (en→month-first,
 *              uk→day-first) via Intl, with a browser fallback. This matches the
 *              behaviour before the preference existed.
 *   - 'dmy'  — DD.MM.YYYY (European)
 *   - 'mdy'  — MM/DD/YYYY (US)
 *   - 'iso'  — YYYY-MM-DD
 *
 * All formatting reads the LOCAL time of the runtime — the value is shown in the
 * viewer's own time zone, like the native toLocale* it replaces. It differs from
 * that native call in three intended ways: the locale comes from the app
 * (en/uk), not the browser; seconds are dropped; and fields are zero-padded.
 */

// App locale code → a BCP-47 tag for Intl in 'auto' mode. An unmapped code is
// passed through (Intl handles e.g. 'de'); an invalid one falls back to the
// browser default.
const LOCALE_TAGS = { en: 'en-US', uk: 'uk-UA' };

function pad(n) {
    return String(n).padStart(2, '0');
}

/**
 * Coerce a value to a Date, or return null for empty/invalid input.
 *
 * @returns {Date | null}
 */
function toDate(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    return value instanceof Date ? value : new Date(value);
}

function autoFormat(date, locale, options) {
    const tag = LOCALE_TAGS[locale] ?? locale ?? undefined;

    try {
        return new Intl.DateTimeFormat(tag, options).format(date);
    } catch {
        // Unsupported locale tag — fall back to the runtime default.
        return new Intl.DateTimeFormat(undefined, options).format(date);
    }
}

/**
 * Format a date (no time) by the given preference.
 *
 * @param {string|number|Date|null} value
 * @param {'auto'|'dmy'|'mdy'|'iso'} [format]
 * @param {string} [locale]  app locale code, used only when format === 'auto'
 * @returns {string}  '' for empty input, '—' for an unparseable value
 */
export function formatDate(value, format = 'auto', locale = 'en') {
    const date = toDate(value);
    if (date === null) {
        return '';
    }
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    if (format === 'auto') {
        return autoFormat(date, locale, { year: 'numeric', month: '2-digit', day: '2-digit' });
    }

    const y = date.getFullYear();
    const m = pad(date.getMonth() + 1);
    const d = pad(date.getDate());

    switch (format) {
        case 'dmy':
            return `${d}.${m}.${y}`;
        case 'mdy':
            return `${m}/${d}/${y}`;
        case 'iso':
        default:
            return `${y}-${m}-${d}`;
    }
}

/**
 * Format a date AND time by the given preference. Time is 24-hour for dmy/iso
 * (and auto-non-US, decided by Intl), 12-hour for mdy (US convention).
 *
 * @param {string|number|Date|null} value
 * @param {'auto'|'dmy'|'mdy'|'iso'} [format]
 * @param {string} [locale]  app locale code, used only when format === 'auto'
 * @returns {string}  '' for empty input, '—' for an unparseable value
 */
export function formatDateTime(value, format = 'auto', locale = 'en') {
    const date = toDate(value);
    if (date === null) {
        return '';
    }
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    if (format === 'auto') {
        return autoFormat(date, locale, {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    const datePart = formatDate(value, format, locale);
    const minute = pad(date.getMinutes());

    if (format === 'mdy') {
        const hour24 = date.getHours();
        const meridiem = hour24 < 12 ? 'AM' : 'PM';
        const hour12 = hour24 % 12 === 0 ? 12 : hour24 % 12;

        return `${datePart} ${hour12}:${minute} ${meridiem}`;
    }

    return `${datePart} ${pad(date.getHours())}:${minute}`;
}
