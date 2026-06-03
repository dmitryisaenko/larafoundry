<script setup>
/**
 * Picks the page shell from the visitor's status (decision D-nav-d, phase 2.3).
 *
 * A persistent Inertia layout: it keys off `nav_level`, the SINGLE source of
 * truth the backend ships from LaraFoundryNavigation::sharedProps (the same
 * value that built the menu). `admin` → operator console, `tenant` → tenant app
 * shell, anything else → the bare base shell. Keying off `nav_level` (not the
 * tenancy module's `activeCompany` prop) means the menu and the shell can never
 * disagree, and a host that wires navigation props but not tenancy props still
 * gets the correct shell. Blocked / deleted users are logged out by
 * EnsureAccountIsActive before a page renders, so they need no shell here.
 *
 * Usage in a host page (persistent layout):
 *
 *     import { LayoutSwitcher } from '@dmitryisaenko/larafoundry';
 *     defineOptions({ layout: LayoutSwitcher });
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppBaseLayout from './AppBaseLayout.vue';
import AppLayout from './AppLayout.vue';
import AdminLayout from './AdminLayout.vue';

const page = usePage();

const layout = computed(() => {
    switch (page.props.nav_level) {
        case 'admin':
            return AdminLayout;
        case 'tenant':
            return AppLayout;
        default:
            return AppBaseLayout;
    }
});
</script>

<template>
    <component :is="layout">
        <slot />
    </component>
</template>
