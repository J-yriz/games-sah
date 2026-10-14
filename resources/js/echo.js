import Echo from 'laravel-echo';

import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Reverb credentials come from Vite env at build/dev time. When they are
// absent (e.g. plain test builds) Echo stays disabled and the wallet page
// keeps working through polling + database re-reads.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
