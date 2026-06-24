import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import ConfirmDialog from '../../resources/js/components/ui/ConfirmDialog.vue';
import { confirm } from '../../resources/js/composables/useConfirm.js';

// The dialog teleports through the core Modal; stub teleport so the content
// renders in place and is assertable. Labels come from props here, so the global
// $t stub (setup.js) is only exercised for the defaults.
const mountOpts = { global: { stubs: { teleport: true } } };

describe('ConfirmDialog', () => {
    it('renders the requested copy and resolves true on confirm', async () => {
        const wrapper = mount(ConfirmDialog, mountOpts);

        const result = confirm({
            variant: 'danger',
            title: 'Delete item',
            message: 'Are you sure?',
            highlight: 'Acme Co',
            confirmLabel: 'Delete',
            cancelLabel: 'Cancel',
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Delete item');
        expect(wrapper.text()).toContain('Are you sure?');
        expect(wrapper.text()).toContain('Acme Co');

        const confirmBtn = wrapper.findAll('button').find((b) => b.text() === 'Delete');
        await confirmBtn.trigger('click');

        await expect(result).resolves.toBe(true);
        wrapper.unmount();
    });

    it('resolves false when cancelled', async () => {
        const wrapper = mount(ConfirmDialog, mountOpts);

        const result = confirm({ title: 'Discard?', confirmLabel: 'Yes', cancelLabel: 'No' });
        await flushPromises();

        const cancelBtn = wrapper.findAll('button').find((b) => b.text() === 'No');
        await cancelBtn.trigger('click');

        await expect(result).resolves.toBe(false);
        wrapper.unmount();
    });

    it('falls back to the global $t labels when none are given', async () => {
        const wrapper = mount(ConfirmDialog, mountOpts);

        const result = confirm({ title: 'Plain' });
        await flushPromises();

        // setup.js stubs $t as identity, so defaults render as their keys.
        expect(wrapper.text()).toContain('Confirm');
        expect(wrapper.text()).toContain('Cancel');

        await wrapper.findAll('button').find((b) => b.text() === 'Confirm').trigger('click');
        await expect(result).resolves.toBe(true);
        wrapper.unmount();
    });
});
