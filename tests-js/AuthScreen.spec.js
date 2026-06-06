import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import { nextTick } from 'vue';
import AuthScreen from '../resources/js/components/auth/AuthScreen.vue';

// Modal-mode teleports to <body>; auto-unmount tears down each wrapper (and its
// teleport) after every test so a previous modal can't leak into the next query.
enableAutoUnmount(afterEach);

/**
 * AuthScreen reads the presentation mode from Inertia's usePage().props. We mock
 * `@inertiajs/vue3` so each test controls `auth_presentation`. The Modal child
 * teleports to <body>, so modal-mode assertions look there.
 */
const sharedProps = { auth_presentation: 'page' };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: sharedProps }),
}));

async function mountScreen(slots = {}) {
    const wrapper = mount(AuthScreen, {
        props: { title: 'Sign in', subtitle: 'Welcome', open: true },
        slots: { default: '<form data-test="body">x</form>', ...slots },
        attachTo: document.body,
    });

    await nextTick();
    await flushPromises();

    return wrapper;
}

describe('AuthScreen', () => {
    beforeEach(() => {
        document.body.className = '';
        sharedProps.auth_presentation = 'page';
    });

    afterEach(() => {
        document.body.className = '';
    });

    it('renders the page surface (AuthCard, no dialog) by default', async () => {
        const wrapper = await mountScreen();

        expect(wrapper.find('[data-test="body"]').exists()).toBe(true);
        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
        expect(wrapper.text()).toContain('Sign in');
    });

    it('falls back to page when the mode is unknown', async () => {
        sharedProps.auth_presentation = 'wat';

        await mountScreen();

        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });

    it('renders the modal surface when mode is modal', async () => {
        sharedProps.auth_presentation = 'modal';

        await mountScreen();

        const dialog = document.body.querySelector('[role="dialog"]');
        expect(dialog).not.toBeNull();
        expect(document.body.textContent).toContain('Sign in');
        expect(document.body.textContent).toContain('x');
    });

    it('bubbles the modal close event to the parent', async () => {
        sharedProps.auth_presentation = 'modal';

        const wrapper = await mountScreen();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('hides the modal body when open is false', async () => {
        sharedProps.auth_presentation = 'modal';

        mount(AuthScreen, {
            props: { title: 'Sign in', open: false },
            slots: { default: '<form data-test="body">x</form>' },
            attachTo: document.body,
        });

        await nextTick();
        await flushPromises();

        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });
});
