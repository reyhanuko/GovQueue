import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Reverb / Laravel Echo connection.
 *
 * wsHost and forceTLS are derived from the page origin rather than left at their
 * defaults, so the socket connects back to the same host that served the page.
 * Without this the browser tries to reach the configured host on port 80 and the
 * connection silently fails on any non-standard dev port.
 */
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? (window.location.protocol === 'https:' ? 'https' : 'http');
const defaultPort = scheme === 'https' ? 443 : 80;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? defaultPort),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80)),
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});

window.Echo.connector.pusher.connection.bind('connected', () => {
    document.dispatchEvent(new CustomEvent('govqueue:connection', { detail: true }));
});

window.Echo.connector.pusher.connection.bind('disconnected', () => {
    document.dispatchEvent(new CustomEvent('govqueue:connection', { detail: false }));
});

window.Echo.connector.pusher.connection.bind('unavailable', () => {
    document.dispatchEvent(new CustomEvent('govqueue:connection', { detail: false }));
});

window.Echo.connector.pusher.connection.bind('failed', () => {
    document.dispatchEvent(new CustomEvent('govqueue:connection', { detail: false }));
});