import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// Shared mock state, hoisted so the vi.mock factory can reference it.
const { post, state } = vi.hoisted(() => ({ post: vi.fn(), state: { props: {} } }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => state,
    router: { post },
    Link: { name: 'Link', props: ['href'], template: '<a><slot /></a>' },
}));

import CookieConsentBanner from '../resources/js/components/legal/CookieConsentBanner.vue';

function withConsent(cookie) {
    state.props = { consent: { cookie } };
    post.mockClear();
    return mount(CookieConsentBanner);
}

describe('CookieConsentBanner', () => {
    it('stays hidden when the feature is disabled', () => {
        const wrapper = withConsent({ enabled: false, decided: false });

        expect(wrapper.find('[role="region"]').exists()).toBe(false);
    });

    it('stays hidden once a decision has been made', () => {
        const wrapper = withConsent({ enabled: true, decided: true });

        expect(wrapper.find('[role="region"]').exists()).toBe(false);
    });

    it('stays hidden by default when the prop is absent', () => {
        state.props = {};
        const wrapper = mount(CookieConsentBanner);

        expect(wrapper.find('[role="region"]').exists()).toBe(false);
    });

    it('shows two choices when enabled and undecided', () => {
        const wrapper = withConsent({ enabled: true, decided: false });

        expect(wrapper.find('[role="region"]').exists()).toBe(true);
        expect(wrapper.findAll('button')).toHaveLength(2);
    });

    it('posts the accepted decision', async () => {
        const wrapper = withConsent({ enabled: true, decided: false });

        await wrapper.findAll('button')[1].trigger('click');

        expect(post).toHaveBeenCalledWith('/consent/cookies', { decision: 'accept' }, expect.any(Object));
    });

    it('posts the necessary-only decision', async () => {
        const wrapper = withConsent({ enabled: true, decided: false });

        await wrapper.findAll('button')[0].trigger('click');

        expect(post).toHaveBeenCalledWith('/consent/cookies', { decision: 'necessary' }, expect.any(Object));
    });
});
