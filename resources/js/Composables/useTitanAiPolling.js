import { onBeforeUnmount } from 'vue';

export function useTitanAiPolling({ isProcessing, getStatusUrl, onComplete }) {
    let pollTimer = null;
    let pollAttempt = 0;

    async function pollSession() {
        if (!isProcessing()) {
            stopPolling();

            return;
        }

        const statusUrl = getStatusUrl();

        if (statusUrl) {
            try {
                const response = await fetch(statusUrl, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.ok) {
                    const data = await response.json();

                    if (data.status !== 'processing') {
                        stopPolling();
                        onComplete();

                        return;
                    }
                }
            } catch {
                // Fall back to the next scheduled poll attempt.
            }
        } else {
            onComplete();
        }

        schedulePoll();
    }

    function schedulePoll() {
        if (!isProcessing()) {
            return;
        }

        const delay = pollAttempt < 3 ? 500 : 2000;
        pollAttempt += 1;
        pollTimer = setTimeout(pollSession, delay);
    }

    function startPolling() {
        stopPolling();

        if (isProcessing()) {
            pollAttempt = 0;
            schedulePoll();
        }
    }

    function stopPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    onBeforeUnmount(stopPolling);

    return { startPolling, stopPolling };
}
