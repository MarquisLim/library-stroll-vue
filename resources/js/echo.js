import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const reverbEnabled = import.meta.env.VITE_REVERB_ENABLED === 'true';
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
const useTls = reverbScheme === 'https';
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT) || (useTls ? 443 : 80);

if (reverbEnabled && reverbKey) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: reverbPort,
        wssPort: reverbPort,
        forceTLS: useTls,
        enabledTransports: useTls ? ['ws', 'wss'] : ['ws'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content'),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        },
    });
}
