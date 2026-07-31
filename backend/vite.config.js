import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        // No `fonts:` entry, and so no `@fonts` directive in any Blade: Geist is
        // self-hosted from `resources/fonts/` and declared in app.css, which
        // removes a third-party font request from the critical path of the
        // public status page. See the comment there.
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        /*
         * Bind IPv4 explicitly. Vite's default resolves to the IPv6 loopback on
         * macOS, so `public/hot` ends up saying `http://[::1]:5173` and Blade emits
         * every dev asset URL against it while `127.0.0.1:5173` refuses outright.
         * It works until something in the chain prefers IPv4, and then the page
         * loads with no stylesheet and no JS and looks like a caching problem.
         */
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
