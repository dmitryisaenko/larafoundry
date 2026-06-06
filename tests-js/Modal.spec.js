import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Modal from '../resources/js/components/ui/Modal.vue';

// Modal teleports to <body>; auto-unmount runs each mounted wrapper's teardown
// (and thus the teleport cleanup) after every test so dialogs don't leak across
// tests and confuse the next test's document.body query.
enableAutoUnmount(afterEach);

/**
 * Modal is teleported to <body>, so we assert against document.body rather than
 * the wrapper, and we clean the body class between tests (the scroll-lock). The
 * teleported content lands after a tick, so the helper awaits it.
 */
async function mountModal(props = {}) {
    const wrapper = mount(Modal, {
        props: { open: true, title: 'My modal', ...props },
        attachTo: document.body,
    });

    await nextTick();
    await flushPromises();

    return wrapper;
}

describe('Modal', () => {
    beforeEach(() => {
        document.body.className = '';
    });

    afterEach(() => {
        document.body.className = '';
    });

    it('renders nothing when closed', async () => {
        await mountModal({ open: false });

        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });

    it('renders the dialog and title when open', async () => {
        await mountModal({ open: true });

        const dialog = document.body.querySelector('[role="dialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog.getAttribute('aria-modal')).toBe('true');
        expect(document.body.textContent).toContain('My modal');
    });

    it('locks body scroll while open and releases it on unmount', async () => {
        const wrapper = await mountModal({ open: true });

        expect(document.body.classList.contains('overflow-hidden')).toBe(true);

        wrapper.unmount();

        expect(document.body.classList.contains('overflow-hidden')).toBe(false);
    });

    it('emits close on the close button', async () => {
        const wrapper = await mountModal({ open: true });

        document.body.querySelector('button[aria-label="Close"]').click();

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('emits close on backdrop click when allowed', async () => {
        const wrapper = await mountModal({ open: true });

        document.body.querySelector('[aria-hidden="true"]').click();

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('does not emit close on backdrop click when disabled', async () => {
        const wrapper = await mountModal({ open: true, closeOnBackdrop: false });

        document.body.querySelector('[aria-hidden="true"]').click();

        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('emits close on Escape when allowed', async () => {
        const wrapper = await mountModal({ open: true });

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('does not emit close on Escape when disabled', async () => {
        const wrapper = await mountModal({ open: true, closeOnEsc: false });

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('keeps the body locked while a second modal is still open (refcount)', async () => {
        const first = await mountModal({ open: true });
        const second = await mountModal({ open: true });

        expect(document.body.classList.contains('overflow-hidden')).toBe(true);

        // First closes — body must stay locked because the second is still open.
        first.unmount();
        expect(document.body.classList.contains('overflow-hidden')).toBe(true);

        // Only when the last one closes is the lock released.
        second.unmount();
        expect(document.body.classList.contains('overflow-hidden')).toBe(false);
    });

    it('restores focus to the trigger when it closes', async () => {
        const trigger = document.createElement('button');
        document.body.appendChild(trigger);
        trigger.focus();
        expect(document.activeElement).toBe(trigger);

        const wrapper = await mountModal({ open: true });
        wrapper.unmount();

        expect(document.activeElement).toBe(trigger);

        trigger.remove();
    });
});
