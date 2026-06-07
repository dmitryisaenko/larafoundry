import { describe, it, expect, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

import DataExport from '../resources/js/Pages/Profile/sections/DataExport.vue';

describe('DataExport', () => {
    it('links to the data-export download endpoint', () => {
        const wrapper = mount(DataExport);
        const link = wrapper.find('a');

        // A plain GET navigation (not an Inertia visit) so the browser receives
        // the streamed file attachment.
        expect(link.attributes('href')).toBe('/profile/data/export');
        expect(link.text()).toBe('Download my data');
    });
});
