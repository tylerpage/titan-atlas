<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:modelValue']);

const editor = ref(null);

function sync() {
    emit('update:modelValue', editor.value?.innerHTML ?? '');
}

function exec(command, value = null) {
    editor.value?.focus();
    document.execCommand(command, false, value);
    sync();
}

function setLink() {
    const url = window.prompt('Link URL (https://...)');

    if (!url) {
        return;
    }

    exec('createLink', url);
}

function setContent(value) {
    if (editor.value && editor.value.innerHTML !== value) {
        editor.value.innerHTML = value || '';
    }
}

onMounted(() => {
    setContent(props.modelValue);
});

watch(
    () => props.modelValue,
    async (value) => {
        await nextTick();
        setContent(value);
    },
);
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-300 bg-white">
        <div class="flex flex-wrap gap-1 border-b border-slate-200 bg-slate-50 px-2 py-2">
            <button type="button" class="rounded px-2 py-1 text-xs font-semibold hover:bg-slate-200" @click="exec('bold')">B</button>
            <button type="button" class="rounded px-2 py-1 text-xs italic hover:bg-slate-200" @click="exec('italic')">I</button>
            <button type="button" class="rounded px-2 py-1 text-xs underline hover:bg-slate-200" @click="exec('underline')">U</button>
            <span class="mx-1 w-px bg-slate-300" />
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-slate-200" @click="exec('formatBlock', 'h2')">H2</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-slate-200" @click="exec('formatBlock', 'h3')">H3</button>
            <span class="mx-1 w-px bg-slate-300" />
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-slate-200" @click="exec('insertUnorderedList')">• List</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-slate-200" @click="exec('insertOrderedList')">1. List</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-slate-200" @click="setLink">Link</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-slate-200" @click="exec('removeFormat')">Clear</button>
        </div>
        <div
            ref="editor"
            contenteditable="true"
            class="min-h-40 px-4 py-3 text-sm leading-relaxed text-slate-800 outline-none"
            @input="sync"
            @blur="sync"
        />
    </div>
</template>
