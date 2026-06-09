<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
    body: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 v-if="title" class="mb-4 text-lg font-semibold">{{ title }}</h2>
        <div
            v-if="body"
            class="cover-rich-text text-sm leading-relaxed text-slate-700"
            v-html="body"
        />
        <p v-else class="text-sm text-slate-500">No content yet.</p>
    </div>
</template>

<style scoped>
.cover-rich-text :deep(h2) {
    margin-top: 1rem;
    margin-bottom: 0.5rem;
    font-size: 1.25rem;
    font-weight: 600;
    color: #0f172a;
}

.cover-rich-text :deep(h3) {
    margin-top: 0.75rem;
    margin-bottom: 0.5rem;
    font-size: 1.125rem;
    font-weight: 600;
    color: #0f172a;
}

.cover-rich-text :deep(p) {
    margin-bottom: 0.75rem;
}

.cover-rich-text :deep(ul),
.cover-rich-text :deep(ol) {
    margin-bottom: 0.75rem;
    padding-left: 1.25rem;
}

.cover-rich-text :deep(ul) {
    list-style-type: disc;
}

.cover-rich-text :deep(ol) {
    list-style-type: decimal;
}

.cover-rich-text :deep(li) {
    margin-bottom: 0.25rem;
}

.cover-rich-text :deep(a) {
    color: #4f46e5;
    text-decoration: underline;
}

.cover-rich-text :deep(blockquote) {
    margin: 0.75rem 0;
    border-left: 3px solid #e2e8f0;
    padding-left: 1rem;
    color: #64748b;
}
</style>
