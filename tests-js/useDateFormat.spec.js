import { describe, it, expect } from 'vitest';
import { formatDate, formatDateTime } from '../resources/js/composables/dateFormat.js';

// A Date built from LOCAL components so the assertions are timezone-independent:
// Feb 5 2026, 14:30 local. (Month is 0-indexed.)
const date = new Date(2026, 1, 5, 14, 30);

describe('formatDate', () => {
    it('formats the explicit preferences deterministically', () => {
        expect(formatDate(date, 'dmy')).toBe('05.02.2026');
        expect(formatDate(date, 'mdy')).toBe('02/05/2026');
        expect(formatDate(date, 'iso')).toBe('2026-02-05');
    });

    it('returns an empty string for empty input and a dash for an unparseable value', () => {
        expect(formatDate('', 'iso')).toBe('');
        expect(formatDate(null, 'iso')).toBe('');
        expect(formatDate(undefined, 'iso')).toBe('');
        expect(formatDate('not-a-date', 'iso')).toBe('—');
    });

    it('auto mode follows the app locale order (month-first en, day-first uk)', () => {
        // Day (05) and month (02) differ, so the leading group reveals the order.
        expect(formatDate(date, 'auto', 'en').startsWith('02')).toBe(true);
        expect(formatDate(date, 'auto', 'uk').startsWith('05')).toBe(true);
    });

    it('accepts an ISO string as well as a Date', () => {
        expect(formatDate('2026-02-05T14:30:00', 'iso')).toBe('2026-02-05');
    });
});

describe('formatDateTime', () => {
    it('uses a 24-hour clock for dmy/iso and a 12-hour clock for mdy', () => {
        expect(formatDateTime(date, 'dmy')).toBe('05.02.2026 14:30');
        expect(formatDateTime(date, 'iso')).toBe('2026-02-05 14:30');
        expect(formatDateTime(date, 'mdy')).toBe('02/05/2026 2:30 PM');
    });

    it('renders midnight and noon correctly on the 12-hour clock', () => {
        expect(formatDateTime(new Date(2026, 1, 5, 0, 5), 'mdy')).toBe('02/05/2026 12:05 AM');
        expect(formatDateTime(new Date(2026, 1, 5, 12, 0), 'mdy')).toBe('02/05/2026 12:00 PM');
    });

    it('returns an empty string for empty input', () => {
        expect(formatDateTime('', 'dmy')).toBe('');
    });
});
