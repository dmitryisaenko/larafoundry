import { describe, it, expect, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

import EmailPreviewFrame from '../resources/js/components/email/EmailPreviewFrame.vue';

describe('EmailPreviewFrame', () => {
    it('renders the html into a sandboxed iframe via srcdoc', () => {
        const wrapper = mount(EmailPreviewFrame, { props: { html: '<p>Hi</p>' } });
        const iframe = wrapper.find('iframe');

        expect(iframe.exists()).toBe(true);
        expect(iframe.attributes('srcdoc')).toBe('<p>Hi</p>');
    });

    it('sandboxes the iframe WITHOUT allow-scripts (the independent barrier)', () => {
        const wrapper = mount(EmailPreviewFrame, { props: { html: '<p>Hi</p>' } });
        const sandbox = wrapper.find('iframe').attributes('sandbox');

        // The preview must never be able to run script — this is the second,
        // independent line of defence on top of the server-side purifier.
        expect(sandbox).toBe('allow-same-origin');
        expect(sandbox).not.toContain('allow-scripts');
    });
});
