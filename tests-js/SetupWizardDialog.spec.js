import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, enableAutoUnmount, flushPromises } from '@vue/test-utils';

enableAutoUnmount(afterEach);

const page = { props: {} };

vi.mock('@inertiajs/vue3', () => ({
    router: { put: vi.fn(), post: vi.fn(), reload: vi.fn() },
    usePage: () => page,
}));

import SetupWizardDialog from '../resources/js/components/setup/SetupWizardDialog.vue';
import { registerSetupStep, setupSteps } from '../resources/js/components/setup/setupWizard.js';
import { router } from '@inertiajs/vue3';

const stubs = {
    Modal: { template: '<div><slot /></div>' },
    InputField: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: `<input :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" />`,
    },
    UserAvatar: { template: '<span />' },
    ImageUpload: { template: '<span />' },
};

function mountWizard(steps) {
    return mount(SetupWizardDialog, { props: { steps }, global: { stubs } });
}

describe('SetupWizardDialog', () => {
    beforeEach(() => {
        page.props = { ui_settings: { theme: 'system', date_format: 'auto' }, locale: 'en', available_locales: [] };
        router.put.mockReset();
        router.reload.mockClear();
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ name: 'Ann', email: 'ann@x.test' }) }));
        Object.keys(setupSteps).forEach((key) => delete setupSteps[key]);
    });

    it('walks the steps in the server\'s order', async () => {
        const wrapper = mountWizard(['theme', 'date']);
        await flushPromises();

        expect(wrapper.find('[data-theme-option]').exists()).toBe(true);

        await wrapper.find('[data-setup-next]').trigger('click');

        expect(wrapper.find('input[name="setup-date-format"]').exists()).toBe(true);
        expect(wrapper.find('[data-setup-next]').text()).toBe('Done');
    });

    it('does not open when the server asks a step it cannot draw - "Done" would finish it unseen', async () => {
        const wrapper = mountWizard(['theme', 'translate', 'date']);
        await flushPromises();

        expect(wrapper.find('[data-setup-wizard]').exists()).toBe(false);
    });

    it('draws a host step registered on the front, with the opening state', async () => {
        registerSetupStep('translate', {
            title: 'Translation',
            component: { props: ['state'], template: '<p data-host-step>{{ state.name }}</p>' },
        });

        const wrapper = mountWizard(['translate']);
        await flushPromises();

        expect(wrapper.find('[data-host-step]').text()).toBe('Ann');
    });

    it('saves the theme through the profile\'s ui-settings at once', async () => {
        const wrapper = mountWizard(['theme']);
        await flushPromises();

        await wrapper.find('[data-theme-option="dark"]').trigger('click');

        expect(router.put).toHaveBeenCalledWith(
            '/profile/ui-settings',
            { key: 'theme', value: 'dark' },
            expect.objectContaining({ only: ['ui_settings'], preserveState: true }),
        );
    });

    it('"Skip" waits for the server, then reads the prop again', async () => {
        const wrapper = mountWizard(['theme', 'date']);
        await flushPromises();

        await wrapper.findAll('button').find((b) => b.text() === 'Skip').trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenLastCalledWith('/setup-wizard/later', expect.objectContaining({ method: 'POST' }));
        expect(router.reload).toHaveBeenCalledWith({ only: ['setup_wizard'] });
    });

    it('"Done" on the last step finishes the wizard', async () => {
        const wrapper = mountWizard(['theme']);
        await flushPromises();

        await wrapper.find('[data-setup-next]').trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenLastCalledWith('/setup-wizard/done', expect.objectContaining({ method: 'POST' }));
        expect(router.reload).toHaveBeenCalledWith({ only: ['setup_wizard'] });
    });

    it('says an expired session apart and keeps the dialog open', async () => {
        const wrapper = mountWizard(['theme']);
        await flushPromises();
        fetch.mockResolvedValueOnce({ ok: false, status: 419 });

        await wrapper.find('[data-setup-next]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Your session has expired. Reload the page.');
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('does not hang when a save gets no Inertia answer - the ways out stay open (review 2026-10-31)', async () => {
        // Only onFinish: a non-Inertia response, a cancelled visit, a dropped connection.
        router.put.mockImplementation((url, data, options) => options.onFinish());

        const wrapper = mountWizard(['about', 'date']);
        await flushPromises();
        await wrapper.findAll('input')[0].setValue('Bob');

        await wrapper.find('[data-setup-next]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Could not save. Try again.');
        expect(wrapper.findAll('button').find((b) => b.text() === 'Skip').attributes('disabled')).toBeUndefined();
    });

    it('says a refusal of a field the step does not show (review 2026-10-31)', async () => {
        router.put.mockImplementation((url, data, options) => {
            options.onError({ email: 'The email is reserved.' });
            options.onFinish();
        });

        const wrapper = mountWizard(['about']);
        await flushPromises();
        await wrapper.findAll('input')[0].setValue('Bob');

        await wrapper.find('[data-setup-next]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('The email is reserved.');
    });

    it('shuts once a way out is saved, whatever the prop says next (review 2026-10-31)', async () => {
        const wrapper = mountWizard(['theme']);
        await flushPromises();

        await wrapper.findAll('button').find((b) => b.text() === 'Skip').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-setup-wizard]').exists()).toBe(false);
    });
});
