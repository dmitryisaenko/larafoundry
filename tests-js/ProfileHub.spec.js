import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// The hub's section forms call Inertia's useForm/router; mock the module so the
// real sections mount without a live Inertia app.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (data = {}) => ({
        ...data,
        errors: {},
        processing: false,
        put: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(),
    }),
    router: { put: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: {} }),
}));

// Stub the core's leaf components/layout so the spec stays on the hub's own
// orchestration (tabs) rather than the whole component graph.
vi.mock('@dmitryisaenko/larafoundry', () => ({
    AppLayout: { name: 'AppLayout', template: '<div><slot /></div>' },
    InputField: { name: 'InputField', template: '<input />' },
    SelectField: { name: 'SelectField', template: '<select />' },
    DateField: { name: 'DateField', template: '<input type="date" />' },
    ImageUpload: { name: 'ImageUpload', template: '<div />' },
    UserAvatar: { name: 'UserAvatar', template: '<span />' },
}));

import ProfileHub from '../resources/js/Pages/Profile/ProfileHub.vue';
import ProfileForm from '../resources/js/Pages/Profile/sections/ProfileForm.vue';
import SessionsManager from '../resources/js/Pages/Profile/sections/SessionsManager.vue';
import DangerZone from '../resources/js/Pages/Profile/sections/DangerZone.vue';

function mountHub(props = {}) {
    return mount(ProfileHub, {
        props: {
            profile: { name: 'A', email: 'a@x.test', has_password: true, email_verified: true, avatar_url: '' },
            sessions: [],
            uiSettings: {},
            uiSettingsSchema: [],
            canDeleteAccount: true,
            pin: { enabled: true, has_pin: false, length: 4 },
            ...props,
        },
    });
}

describe('ProfileHub', () => {
    it('renders one button per tab', () => {
        const wrapper = mountHub();
        expect(wrapper.findAll('nav button')).toHaveLength(6);
    });

    it('shows the profile form first', () => {
        const wrapper = mountHub();
        expect(wrapper.findComponent(ProfileForm).exists()).toBe(true);
        expect(wrapper.findComponent(SessionsManager).exists()).toBe(false);
    });

    it('switches sections when a tab is clicked', async () => {
        const wrapper = mountHub();
        await wrapper.findAll('nav button')[3].trigger('click');

        expect(wrapper.findComponent(SessionsManager).exists()).toBe(true);
        expect(wrapper.findComponent(ProfileForm).exists()).toBe(false);
    });

    it('passes the delete permission down to the danger zone', async () => {
        const wrapper = mountHub({ canDeleteAccount: false });
        await wrapper.findAll('nav button')[5].trigger('click');

        const danger = wrapper.findComponent(DangerZone);
        expect(danger.exists()).toBe(true);
        expect(danger.props('canDelete')).toBe(false);
    });
});
