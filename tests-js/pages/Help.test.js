import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key) => key }) }));

// The editor's writing surface needs Tiptap, which the core does not install:
// stand in a textarea bound the same way (v-model over the HTML string).
vi.mock('../../resources/js/components/help/HelpRichText.vue', () => ({
    default: {
        name: 'HelpRichText',
        props: ['modelValue', 'disabled', 'uploadUrl'],
        emits: ['update:modelValue', 'uploaded'],
        template: '<textarea class="rt-stub" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
}));

import HelpIndex from '../../resources/js/Pages/Help/Index.vue';
import HelpEditor from '../../resources/js/Pages/Admin/Help/Editor.vue';

const shell = {
    global: {
        stubs: {
            AppBaseLayout: { template: '<div><slot /></div>' },
            AdminLayout: { template: '<div><slot /></div>' },
        },
    },
};

function pages() {
    return {
        start: {
            title: 'Start',
            blurb: 'First steps',
            items: [
                { heading: 'Both', body: '<p>desk</p>', body_locale: null, body_mobile: '<p>phone</p>', body_mobile_locale: null },
                { heading: 'Substitute', body: '<p>original</p>', body_locale: 'uk', body_mobile: null, body_mobile_locale: null },
            ],
        },
        billing: { title: 'Billing', blurb: '', items: [] },
    };
}

describe('Help/Index (the reader)', () => {
    it('opens the asked tab and keeps articles closed until pressed', async () => {
        const wrapper = mount(HelpIndex, { ...shell, props: { pages: pages(), open: 'start' } });

        expect(wrapper.text()).toContain('First steps');
        expect(wrapper.find('.help-body').exists()).toBe(false);

        await wrapper.findAll('button[aria-expanded]')[0].trigger('click');

        const bodies = wrapper.findAll('.help-body');
        expect(bodies.map((b) => b.html())).toEqual([
            expect.stringContaining('<p>desk</p>'),
            expect.stringContaining('<p>phone</p>'),
        ]);
        // The desktop text hides below md only where a phone text exists.
        expect(bodies[0].element.parentElement.className).toContain('hidden md:block');
        expect(bodies[1].element.parentElement.className).toContain('md:hidden');
    });

    it('says when a text is a substitute for the reader\'s language', async () => {
        const wrapper = mount(HelpIndex, { ...shell, props: { pages: pages(), open: 'start' } });

        await wrapper.findAll('button[aria-expanded]')[1].trigger('click');

        expect(wrapper.find('.help-note').text()).toContain('There is no translation into your language yet');
        expect(wrapper.find('.help-body').element.parentElement.className).not.toContain('hidden');
    });

    it('switches tabs and falls back to the first for an unknown one', async () => {
        const wrapper = mount(HelpIndex, { ...shell, props: { pages: pages(), open: 'nope' } });

        expect(wrapper.text()).toContain('First steps');

        await wrapper.findAll('nav button').find((b) => b.text() === 'Billing').trigger('click');

        expect(wrapper.text()).toContain('Nothing here yet.');
    });
});

function sections() {
    return [{
        id: 1,
        key: 'start',
        title: { uk: 'Початок' },
        blurb: {},
        position: 10,
        is_published: true,
        articles: [{
            id: 7,
            section_id: 1,
            heading: { uk: 'Стаття', en: 'Article' },
            body: { uk: '<p>Текст</p>' },
            body_mobile: {},
            position: 10,
            is_published: true,
        }],
    }];
}

describe('Admin/Help/Editor', () => {
    beforeEach(() => {
        window.localStorage.clear();
        window.localStorage.setItem('larafoundry.help.view', 'help');
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ article: {} }) }));
    });

    function editor(props = {}) {
        return mount(HelpEditor, {
            ...shell,
            props: { sections: sections(), pictures: [], locales: ['uk', 'en'], source: 'uk', ...props },
        });
    }

    async function openArticleIn(wrapper, code) {
        await wrapper.findAll('button').find((b) => b.text() === 'Стаття').trigger('click');

        if (code) await articleLanguage(wrapper, code);
    }

    // The article's own language row is the LAST one on the page: the section above
    // it has a row of its own, and pressing that one switches nothing in the article.
    async function articleLanguage(wrapper, code) {
        const name = { en: 'english', uk: 'українська' }[code];

        await wrapper.findAll('button').filter((b) => b.text().toLowerCase().startsWith(name)).at(-1).trigger('click');
    }

    it('shows no translate button until a translator is bound', async () => {
        const wrapper = editor({ can_translate: false });
        await openArticleIn(wrapper, 'en');

        expect(wrapper.findAll('button').some((b) => b.text() === 'Translate')).toBe(false);
    });

    it('offers translation on a translated language, never on the source', async () => {
        const wrapper = editor({ can_translate: true });
        await openArticleIn(wrapper);

        expect(wrapper.findAll('button').some((b) => b.text() === 'Translate')).toBe(false);

        await articleLanguage(wrapper, 'en');

        expect(wrapper.findAll('button').some((b) => b.text() === 'Translate')).toBe(true);
    });

    it('saves the article with both texts', async () => {
        const wrapper = editor();
        await openArticleIn(wrapper);

        await wrapper.findAll('button').find((b) => b.text() === 'Phone').trigger('click');
        await wrapper.find('.rt-stub').setValue('<p>Телефон</p>');
        await wrapper.findAll('button').find((b) => b.text() === 'Save the article').trigger('click');
        await flushPromises();

        const [url, init] = global.fetch.mock.calls.at(-1);
        expect(url).toBe('/admin/help-articles/articles/7');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(init.body)).toMatchObject({
            body: { uk: '<p>Текст</p>' },
            body_mobile: { uk: '<p>Телефон</p>' },
        });
    });

    it('marks an article missing in a language as untranslated in the overview', async () => {
        window.localStorage.setItem('larafoundry.help.view', 'overview');
        const wrapper = editor();

        // Heading in English but no English text: half a translation is not one.
        expect(wrapper.find('tbody').text()).toContain('✕');
        expect(wrapper.find('tbody').text()).toContain('0/1');
    });
});
