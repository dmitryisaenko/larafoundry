import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';

// The component reads flash messages from usePage().props.flash and watches it
// deeply. We expose a single reactive page object the test mutates to simulate
// new Inertia shared props arriving.
const page = reactive({ props: { flash: {} } });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
}));

import AppFlashMessage from '../../resources/js/components/AppFlashMessage.vue';

describe('AppFlashMessage', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        page.props.flash = {};
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders a flash info message from the flash payload', async () => {
        page.props.flash = { info: 'Saved.' };
        const wrapper = mount(AppFlashMessage);
        await nextTick();

        expect(wrapper.text()).toContain('Saved.');
    });

    it('auto-dismisses a disappear message after the timeout', async () => {
        page.props.flash = { disappear_info: 'Quick note' };
        const wrapper = mount(AppFlashMessage);
        await nextTick();
        expect(wrapper.text()).toContain('Quick note');

        // AUTO_DISMISS_MS is 2500ms.
        vi.advanceTimersByTime(2500);
        await nextTick();

        expect(wrapper.text()).not.toContain('Quick note');
    });

    // Regression test (Ф0.4): the auto-dismiss timer is keyed by `slot:content`,
    // not the bare slot. So when a NEW disappear message replaces an old one in
    // the same slot, the old (already-elapsed) timer must NOT close the new
    // message. Previously the timer was tied to the slot and a stale timer would
    // wrongly dismiss the replacement.
    it('does not let a stale timer close a replacement message in the same slot', async () => {
        page.props.flash = { disappear_info: 'First message' };
        const wrapper = mount(AppFlashMessage);
        await nextTick();
        expect(wrapper.text()).toContain('First message');

        // Advance most of the way through the first message's timer, then swap
        // in a new message in the SAME disappear-info slot.
        vi.advanceTimersByTime(2400);
        page.props.flash = { disappear_info: 'Second message' };
        await nextTick();

        // The old message is gone, the new one is shown.
        expect(wrapper.text()).not.toContain('First message');
        expect(wrapper.text()).toContain('Second message');

        // Advancing past where the FIRST timer would have fired (2400 + 200 = 2600,
        // past the original 2500) must NOT close the new message — its own timer
        // is fresh and keyed to its own content.
        vi.advanceTimersByTime(200);
        await nextTick();
        expect(wrapper.text()).toContain('Second message');

        // The new message's own full timer (started at the swap) still works.
        vi.advanceTimersByTime(2300);
        await nextTick();
        expect(wrapper.text()).not.toContain('Second message');
    });

    it('shows a close button for persistent (non-disappear) messages', async () => {
        page.props.flash = { info: 'Persistent' };
        const wrapper = mount(AppFlashMessage);
        await nextTick();

        const closeBtn = wrapper.find('button[aria-label="Close"]');
        expect(closeBtn.exists()).toBe(true);

        await closeBtn.trigger('click');
        expect(wrapper.text()).not.toContain('Persistent');
    });
});
