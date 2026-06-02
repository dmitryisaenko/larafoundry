import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@dmitryisaenko/larafoundry': fileURLToPath(
                new URL('./resources/js/index.js', import.meta.url)
            ),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        root: '.',
        include: ['tests-js/**/*.{test,spec}.js'],
        setupFiles: ['tests-js/setup.js'],
    },
});
