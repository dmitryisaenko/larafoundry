import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import TicketStatusBadge from '../../resources/js/components/tickets/TicketStatusBadge.vue';
import TicketPriorityBadge from '../../resources/js/components/tickets/TicketPriorityBadge.vue';

// $t is stubbed to identity in tests-js/setup.js, so a label renders its key.

describe('TicketStatusBadge', () => {
    it('renders the status label key and a status-specific class', () => {
        const wrapper = mount(TicketStatusBadge, { props: { status: 'resolved' } });

        expect(wrapper.text()).toBe('tickets.status.resolved');
        expect(wrapper.classes().join(' ')).toContain('emerald');
    });

    it('falls back to a neutral class for an unknown status', () => {
        const wrapper = mount(TicketStatusBadge, { props: { status: 'whatever' } });

        expect(wrapper.classes().join(' ')).toContain('surface-accent');
    });
});

describe('TicketPriorityBadge', () => {
    it('stands out for high priority', () => {
        const wrapper = mount(TicketPriorityBadge, { props: { priority: 'high' } });

        expect(wrapper.text()).toBe('tickets.priority.high');
        expect(wrapper.classes().join(' ')).toContain('danger');
    });

    it('uses the neutral style for standard priority', () => {
        const wrapper = mount(TicketPriorityBadge, { props: { priority: 'standard' } });

        expect(wrapper.classes().join(' ')).toContain('surface-accent');
    });
});
