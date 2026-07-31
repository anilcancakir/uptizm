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
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
