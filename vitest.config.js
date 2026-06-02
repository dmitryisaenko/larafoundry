import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: true,
        root: '.',
        include: ['tests-js/**/*.{test,spec}.js'],
        setupFiles: ['tests-js/setup.js'],
    },
});
