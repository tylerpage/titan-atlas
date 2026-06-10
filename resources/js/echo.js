import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echoInstance = null;

export function configureEcho(broadcast) {
    if (!broadcast?.enabled || echoInstance) {
        return echoInstance;
    }

    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: broadcast.key,
        wsHost: broadcast.host,
        wsPort: broadcast.port,
        wssPort: broadcast.port,
        forceTLS: broadcast.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        },
    });

    window.Echo = echoInstance;

    return echoInstance;
}

export function getEcho() {
    return echoInstance;
}
