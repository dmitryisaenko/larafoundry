import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

vi.mock('@inertiajs/vue3', () => ({
    router: { put: vi.fn() },
}));

// Stub SelectField so the boolean-only spec does not pull the full barrel/i18n.
vi.mock('@dmitryisaenko/larafoundry', () => ({
    SelectField: { name: 'SelectField', template: '<select />' },
}));

import Appearance from '../resources/js/Pages/Profile/sections/Appearance.vue';
import { router } from '@inertiajs/vue3';

describe('Appearance', () => {
    beforeEach(() => router.put.mockClear());

    it('saves a boolean preference on toggle', async () => {
        const wrapper = mount(Appearance, {
            props: {
                settings: { sidebar_collapsed: false },
                schema: [{ key: 'sidebar_collapsed', type: 'boolean', default: false, options: [] }],
            },
        });

        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(router.put).toHaveBeenCalledWith(
            '/profile/ui-settings',
            { key: 'sidebar_collapsed', value: true },
            { preserveScroll: true, preserveState: true },
        );
    });
});
