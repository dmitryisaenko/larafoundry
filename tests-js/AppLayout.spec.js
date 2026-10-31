import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { setup_wizard: ['theme'] } }),
    router: { on: vi.fn(), reload: vi.fn(), put: vi.fn(), post: vi.fn() },
}));

import AppLayout from '../resources/js/layouts/AppLayout.vue';
import { appShell, registerAppShell } from '../resources/js/layouts/appShell.js';

describe('AppLayout', () => {
    afterEach(() => {
        appShell.component = null;
    });

    it('draws the page inside the host shell once one is registered, and nothing of its own', () => {
        registerAppShell({ template: '<div data-host-shell><slot /></div>' });

        const wrapper = mount(AppLayout, { slots: { default: '<p data-page>page</p>' } });

        expect(wrapper.find('[data-host-shell] [data-page]').exists()).toBe(true);
        // The host shell owns the frame - the core's header and wizard dialog are not drawn.
        expect(wrapper.find('header').exists()).toBe(false);
        expect(wrapper.find('[data-setup-wizard]').exists()).toBe(false);
    });
});
