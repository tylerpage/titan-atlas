import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount } from 'vue';
import { configureEcho, getEcho } from '../echo';
import { useTitanAiPolling } from './useTitanAiPolling';

export function useTitanAiSessionWatch({
    isProcessing,
    getChannelName,
    getStatusUrl,
    onComplete,
}) {
    const page = usePage();
    const broadcast = computed(() => page.props.broadcast ?? { enabled: false });
    let activeChannelName = null;

    const polling = useTitanAiPolling({
        isProcessing,
        getStatusUrl,
        onComplete,
    });

    function handleSessionUpdated(payload) {
        if (!payload || payload.status === 'processing') {
            return;
        }

        stopWatching();
        onComplete();
    }

    function subscribeToChannel() {
        const channelName = getChannelName();

        if (!channelName || !isProcessing()) {
            return;
        }

        // Always poll so chat works when the queue is reachable but Reverb is not
        // (e.g. ngrok + BROADCAST_CONNECTION=log, or Reverb not running locally).
        polling.startPolling();

        if (!broadcast.value.enabled) {
            return;
        }

        configureEcho(broadcast.value);
        const echo = getEcho();

        if (!echo) {
            return;
        }

        unsubscribeFromChannel();
        activeChannelName = channelName;

        echo.private(channelName)
            .listen('.session.updated', handleSessionUpdated)
            .error(() => {
                // Keep polling running if the websocket subscription fails.
            });
    }

    function unsubscribeFromChannel() {
        const echo = getEcho();

        if (!echo || !activeChannelName) {
            return;
        }

        echo.leave(`private-${activeChannelName}`);
        activeChannelName = null;
    }

    function startWatching() {
        if (!isProcessing()) {
            stopWatching();

            return;
        }

        subscribeToChannel();
    }

    function stopWatching() {
        unsubscribeFromChannel();
        polling.stopPolling();
    }

    onBeforeUnmount(stopWatching);

    return {
        startWatching,
        stopWatching,
    };
}
