import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
            // The comp specifies DM Sans for body and Manrope for headings.
            // Served through Bunny rather than Google Fonts: same faces, no
            // third-party tracking on every page load.
            fonts: [
                bunny('DM Sans', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('Manrope', {
                    weights: [600, 700, 800],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
