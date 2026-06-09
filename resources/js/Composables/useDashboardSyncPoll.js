import { onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';

export function useDashboardSyncPoll(isSyncing, { only = ['dashboard'], intervalMs = 10000 } = {}) {
    let timer = null;

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function start() {
        stop();
        timer = setInterval(() => {
            router.reload({ only, preserveScroll: true, preserveState: true });
        }, intervalMs);
    }

    watch(
        isSyncing,
        (syncing) => {
            if (syncing) {
                start();
            } else {
                stop();
            }
        },
        { immediate: true },
    );

    onUnmounted(stop);
}
