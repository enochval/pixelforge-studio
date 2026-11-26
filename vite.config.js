import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],

    /*
     * The dev server runs in a container, so the address it binds to and the
     * address the browser must use are not the same one.
     *
     * `host: '0.0.0.0'` makes it listen on every interface inside the
     * container, which is what lets the published port reach it at all.
     *
     * `hmr.host` is the part that is easy to miss. laravel-vite-plugin writes
     * the dev server URL into public/hot, and the Blade directive serves that
     * URL to the browser as the source of every module script. Without an HMR
     * host it writes the bind address — so the page asks the browser to load
     * modules from http://0.0.0.0:5173, every one of them fails, and the page
     * renders blank with no server-side error to show why.
     */
    server: {
        host: '0.0.0.0',
        port: 5173,

        // Fail loudly if the port is taken rather than silently moving to
        // another one that public/hot would then point away from.
        strictPort: true,

        hmr: {
            host: 'localhost',
        },
    },
});
