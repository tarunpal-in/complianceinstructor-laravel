import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
            // DM Sans throughout — body and headings. The comp pairs it with
            // Manrope for headings; that second family was dropped by request,
            // so headings now render in DM Sans at the same weights.
            // Served through Bunny rather than Google Fonts: same faces, no
            // third-party tracking on every page load.
            fonts: [
                bunny('DM Sans', {
                    weights: [400, 500, 600, 700],
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
