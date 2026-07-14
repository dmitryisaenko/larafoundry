import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * The <OnboardingChecklist> reads the `onboarding` shared Inertia prop (phase
 * 5.4) and shows the remaining steps with a progress indicator — a gentle,
 * dismissible card. It renders NOTHING when there is nothing to nudge (prop
 * absent, dismissed, all complete, or no steps). We mock `@inertiajs/vue3` so
 * each test controls the page props and can spy on the dismiss POST.
 */
const { pageProps, routerMock } = vi.hoisted(() => ({
    pageProps: { current: {} },
    routerMock: { post: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps.current }),
    router: routerMock,
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import OnboardingChecklist from '../../resources/js/components/OnboardingChecklist.vue';

function checklist(overrides = {}) {
    return {
        dismissed: false,
        completed: 1,
        total: 3,
        all_complete: false,
        steps: [
            { key: 'profile', title: 'Complete your profile', description: 'Add your name.', cta_url: '/profile', cta_label: 'Go to profile', complete: true },
            { key: 'two_factor', title: 'Secure your account', description: 'Turn on 2FA.', cta_url: '/profile', cta_label: 'Enable 2FA', complete: false },
            { key: 'company_setup', title: 'Set up your company', description: 'Add details.', cta_url: null, cta_label: 'Set up company', complete: false },
        ],
        ...overrides,
    };
}

describe('OnboardingChecklist', () => {
    beforeEach(() => {
        pageProps.current = {};
        routerMock.post.mockClear();
    });

    it('renders the heading, progress and a row per step when there is work left', () => {
        pageProps.current = { onboarding: checklist() };

        const wrapper = mount(OnboardingChecklist);

        expect(wrapper.text()).toContain('Getting started');
        expect(wrapper.text()).toContain('1 / 3');
        expect(wrapper.text()).toContain('Complete your profile');
        expect(wrapper.text()).toContain('Enable 2FA');
        expect(wrapper.findAll('li')).toHaveLength(3);
    });

    it('renders a CTA link only for incomplete steps that carry a url', () => {
        pageProps.current = { onboarding: checklist() };

        const links = mount(OnboardingChecklist).findAll('a');

        // Only the two_factor step is incomplete AND has a cta_url; the completed
        // profile step and the url-less company step render no link.
        expect(links).toHaveLength(1);
        expect(links[0].attributes('href')).toBe('/profile');
    });

    it('posts to the dismiss route when Hide is clicked', async () => {
        pageProps.current = { onboarding: checklist() };

        const wrapper = mount(OnboardingChecklist);
        await wrapper.find('button').trigger('click');

        expect(routerMock.post).toHaveBeenCalledWith('/onboarding/dismiss', {}, { preserveScroll: true });
    });

    it('renders nothing when the onboarding prop is absent', () => {
        pageProps.current = {};

        expect(mount(OnboardingChecklist).text()).toBe('');
    });

    it('renders nothing when the checklist is dismissed', () => {
        pageProps.current = { onboarding: checklist({ dismissed: true, steps: [] }) };

        expect(mount(OnboardingChecklist).text()).toBe('');
    });

    it('renders nothing when every step is complete', () => {
        pageProps.current = { onboarding: checklist({ all_complete: true }) };

        expect(mount(OnboardingChecklist).text()).toBe('');
    });

    it('renders nothing when there are no steps at all', () => {
        pageProps.current = { onboarding: checklist({ total: 0, steps: [] }) };

        expect(mount(OnboardingChecklist).text()).toBe('');
    });

    it('mounts without error when given no props at all', () => {
        pageProps.current = { onboarding: undefined };

        expect(() => mount(OnboardingChecklist)).not.toThrow();
    });
});
