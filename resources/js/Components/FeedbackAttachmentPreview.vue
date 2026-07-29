<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    attachments: {
        type: Array,
        default: () => [],
    },
});

const imageAttachments = computed(() => props.attachments.filter((attachment) => attachment.is_image && attachmentPreviewSrc(attachment)));
const nonImageAttachments = computed(() => props.attachments.filter((attachment) => !attachment.is_image));

function attachmentPreviewSrc(attachment) {
    return attachment.preview_src || attachment.preview_url || null;
}

const lightboxOpen = ref(false);
const activeAttachment = ref(null);
const zoom = ref(1);

function openLightbox(attachment) {
    activeAttachment.value = attachment;
    zoom.value = 1;
    lightboxOpen.value = true;
}

function closeLightbox() {
    lightboxOpen.value = false;
    activeAttachment.value = null;
    zoom.value = 1;
}

function zoomIn() {
    zoom.value = Math.min(4, Math.round((zoom.value + 0.25) * 100) / 100);
}

function zoomOut() {
    zoom.value = Math.max(0.5, Math.round((zoom.value - 0.25) * 100) / 100);
}

function resetZoom() {
    zoom.value = 1;
}

function onWheel(event) {
    if (!lightboxOpen.value) {
        return;
    }

    event.preventDefault();

    if (event.deltaY < 0) {
        zoomIn();
    } else {
        zoomOut();
    }
}

function onKeydown(event) {
    if (!lightboxOpen.value) {
        return;
    }

    if (event.key === 'Escape') {
        closeLightbox();
    }
}

watch(lightboxOpen, (open) => {
    if (open) {
        window.addEventListener('keydown', onKeydown);

        return;
    }

    window.removeEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div v-if="attachments.length" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold">Attachments</h2>

        <div v-if="imageAttachments.length" class="mt-4 grid gap-4 sm:grid-cols-2">
            <button
                v-for="attachment in imageAttachments"
                :key="attachment.id"
                type="button"
                class="group overflow-hidden rounded-lg border border-slate-200 bg-slate-50 text-left transition hover:border-slate-300 hover:shadow-sm"
                @click="openLightbox(attachment)"
            >
                <img
                    :src="attachmentPreviewSrc(attachment)"
                    :alt="attachment.original_filename"
                    class="max-h-72 w-full object-contain transition group-hover:scale-[1.01]"
                />
                <div class="border-t border-slate-200 px-3 py-2 text-sm">
                    <p class="truncate font-medium text-slate-800">{{ attachment.original_filename }}</p>
                    <p class="text-slate-500">Click to zoom · {{ Math.round(attachment.size_bytes / 1024) }} KB</p>
                </div>
            </button>
        </div>

        <ul v-if="nonImageAttachments.length" class="mt-4 space-y-2 text-sm">
            <li v-for="attachment in nonImageAttachments" :key="attachment.id">
                <a
                    :href="attachment.download_url"
                    class="text-primary hover:underline"
                >
                    {{ attachment.original_filename }}
                </a>
                <span class="text-slate-500">
                    ({{ Math.round(attachment.size_bytes / 1024) }} KB)
                </span>
            </li>
        </ul>
    </div>

    <div
        v-if="lightboxOpen && activeAttachment"
        class="fixed inset-0 z-50 flex flex-col bg-slate-950/90"
        @click.self="closeLightbox"
        @wheel="onWheel"
    >
        <div class="flex items-center justify-between gap-3 px-4 py-3 text-white">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">{{ activeAttachment.original_filename }}</p>
                <p class="text-xs text-slate-300">Scroll or use buttons to zoom · Esc to close</p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10"
                    @click="zoomOut"
                >
                    −
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10"
                    @click="resetZoom"
                >
                    {{ Math.round(zoom * 100) }}%
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10"
                    @click="zoomIn"
                >
                    +
                </button>
                <a
                    :href="activeAttachment.download_url"
                    class="rounded-lg border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10"
                >
                    Download
                </a>
                <button
                    type="button"
                    class="rounded-lg border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10"
                    @click="closeLightbox"
                >
                    Close
                </button>
            </div>
        </div>

        <div class="flex flex-1 items-center justify-center overflow-auto p-4">
            <img
                :src="attachmentPreviewSrc(activeAttachment)"
                :alt="activeAttachment.original_filename"
                class="max-w-none origin-center transition-transform duration-150"
                :style="{ transform: `scale(${zoom})` }"
            />
        </div>
    </div>
</template>
