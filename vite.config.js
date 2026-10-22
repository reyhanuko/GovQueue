import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Bind to IPv4 only. Without this Vite also listens on [::1], and its
        // HMR scripts arrive from that address. The CSP policy only allows
        // 127.0.0.1, so every HMR injection triggers a CSP violation report
        // even though the policy is still Report-Only. Pinning to IPv4 keeps
        // all Vite traffic on the same origin the CSP already allows.
        host: '127.0.0.1',
    },
});
