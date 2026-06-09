import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useAppBranding() {
    const page = usePage();

    const appName = computed(() => page.props.app?.name ?? 'Atlas');
    const aiName = computed(() => page.props.app?.ai_name ?? 'TitanAI');

    return { appName, aiName };
}
