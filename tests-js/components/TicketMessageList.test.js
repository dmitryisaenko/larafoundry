import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import TicketMessageList from '../../resources/js/components/tickets/TicketMessageList.vue';

const messages = [
    { id: 1, message: 'Hi, I need help', is_agent: false, created_human: '2 min ago', author: { id: 1, name: 'Alice' } },
    { id: 2, message: 'Happy to help!', is_agent: true, created_human: '1 min ago', author: { id: 9, name: 'Op' } },
];

describe('TicketMessageList', () => {
    it('renders message bodies as text', () => {
        const wrapper = mount(TicketMessageList, { props: { messages } });

        expect(wrapper.text()).toContain('Hi, I need help');
        expect(wrapper.text()).toContain('Happy to help!');
    });

    it('escapes HTML in a message body — never v-html (security finding S1)', () => {
        const wrapper = mount(TicketMessageList, {
            props: {
                messages: [
                    { id: 1, message: '<img src=x onerror="alert(1)">', is_agent: false, created_human: 'now', author: { name: 'A' } },
                ],
            },
        });

        // Interpolated as text: the crafted <img> is never injected as an element.
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.html()).toContain('&lt;img');
    });

    it('shows the support label for an operator message and the author name for the customer', () => {
        const wrapper = mount(TicketMessageList, { props: { messages } });

        // The customer's own message shows their name; the operator side shows the support label key.
        expect(wrapper.text()).toContain('Alice');
        expect(wrapper.text()).toContain('Support team');
    });

    it('renders an empty thread without error', () => {
        const wrapper = mount(TicketMessageList, { props: { messages: [] } });

        expect(wrapper.findAll('li')).toHaveLength(0);
    });
});
