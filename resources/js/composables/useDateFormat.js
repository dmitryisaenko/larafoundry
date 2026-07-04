import { usePage } from '@inertiajs/vue3';
import { formatDate, formatDateTime } from './dateFormat.js';

/**
 * Date formatters bound to the current user's `date_format` and `time_format`
 * preferences and the active app locale, all read from Inertia shared props
 * (`ui_settings` and `locale`). Use inside `<script setup>`:
 *
 *   const { formatDate, formatDateTime } = useDateFormat();
 *   ...
 *   {{ formatDateTime(session.last_activity) }}
 *
 * The page props are reactive, so a locale or preference change re-renders the
 * formatted output without re-instantiating the composable. Falls back to
 * 'auto' / 'en' when the props are absent (e.g. an unauthenticated context).
 *
 * @returns {{ formatDate: (value: any) => string, formatDateTime: (value: any) => string }}
 */
export function useDateFormat() {
    const page = usePage();

    const resolve = () => {
        const props = page.props ?? {};

        return {
            format: props.ui_settings?.date_format ?? 'auto',
            timeFormat: props.ui_settings?.time_format ?? 'auto',
            locale: props.locale ?? 'en',
        };
    };

    return {
        formatDate: (value) => {
            const { format, locale } = resolve();

            return formatDate(value, format, locale);
        },
        formatDateTime: (value) => {
            const { format, timeFormat, locale } = resolve();

            return formatDateTime(value, format, locale, timeFormat);
        },
    };
}
