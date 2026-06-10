<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import FeedbackWidget from '../Components/FeedbackWidget.vue';

defineProps({
    title: {
        type: String,
        default: null,
    },
});

const page = usePage();

const user = computed(() => page.props.auth.user);
const flashStatus = computed(() => page.props.flash.status);
const impersonation = computed(() => page.props.impersonation);
const appName = computed(() => page.props.app.name);
const pendingFeedbackCount = computed(() => page.props.feedback?.pending_count ?? 0);
const mobileMenuOpen = ref(false);

watch(
    () => page.url,
    () => {
        mobileMenuOpen.value = false;
    },
);

function logout() {
    mobileMenuOpen.value = false;
    router.post(route('logout'));
}

function stopImpersonating() {
    router.post(route('admin.impersonate.destroy'));
}

function closeMobileMenu() {
    mobileMenuOpen.value = false;
}
</script>

<template>
    <Head :title="title" />

    <header class="border-b border-slate-800 bg-slate-900">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <Link :href="route('home')" class="block shrink-0">
                <img src="/logo.svg" :alt="appName" class="h-8 w-auto" />
            </Link>

            <nav v-if="user" class="hidden items-center gap-4 text-sm lg:flex">
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.dashboards.index')"
                    class="text-slate-300 hover:text-white"
                >
                    Dashboards
                </Link>
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.ai-connectors.index')"
                    class="text-slate-300 hover:text-white"
                >
                    AI Connectors
                </Link>
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.companies.index')"
                    class="text-slate-300 hover:text-white"
                >
                    Companies
                </Link>
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.users.index')"
                    class="text-slate-300 hover:text-white"
                >
                    Users
                </Link>
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.feedback.index')"
                    class="text-slate-300 hover:text-white"
                >
                    Feedback
                    <span
                        v-if="pendingFeedbackCount"
                        class="ml-1 rounded-full bg-amber-400 px-1.5 py-0.5 text-xs font-semibold text-slate-900"
                    >
                        {{ pendingFeedbackCount }}
                    </span>
                </Link>
                <span class="text-slate-400">{{ user.name }}</span>
                <button type="button" class="text-slate-300 hover:text-white" @click="logout">
                    Log out
                </button>
            </nav>

            <button
                v-if="user"
                type="button"
                class="inline-flex items-center justify-center rounded-lg p-2 text-slate-300 hover:bg-slate-800 hover:text-white lg:hidden"
                :aria-expanded="mobileMenuOpen"
                aria-controls="mobile-nav"
                @click="mobileMenuOpen = !mobileMenuOpen"
            >
                <span class="sr-only">{{ mobileMenuOpen ? 'Close menu' : 'Open menu' }}</span>
                <svg
                    v-if="!mobileMenuOpen"
                    class="h-6 w-6"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.5"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                <svg
                    v-else
                    class="h-6 w-6"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.5"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <nav
            v-if="user && mobileMenuOpen"
            id="mobile-nav"
            class="border-t border-slate-800 px-6 py-4 lg:hidden"
        >
            <div class="flex flex-col gap-1 text-sm">
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.dashboards.index')"
                    class="rounded-lg px-3 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                    @click="closeMobileMenu"
                >
                    Dashboards
                </Link>
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.ai-connectors.index')"
                    class="rounded-lg px-3 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                    @click="closeMobileMenu"
                >
                    AI Connectors
                </Link>
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.companies.index')"
                    class="rounded-lg px-3 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                    @click="closeMobileMenu"
                >
                    Companies
                </Link>
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.users.index')"
                    class="rounded-lg px-3 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                    @click="closeMobileMenu"
                >
                    Users
                </Link>
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.feedback.index')"
                    class="rounded-lg px-3 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                    @click="closeMobileMenu"
                >
                    Feedback
                    <span
                        v-if="pendingFeedbackCount"
                        class="ml-1 rounded-full bg-amber-400 px-1.5 py-0.5 text-xs font-semibold text-slate-900"
                    >
                        {{ pendingFeedbackCount }}
                    </span>
                </Link>
                <div class="mt-2 border-t border-slate-800 pt-3">
                    <p class="px-3 py-1 text-slate-400">{{ user.name }}</p>
                    <button
                        type="button"
                        class="w-full rounded-lg px-3 py-2 text-left text-slate-300 hover:bg-slate-800 hover:text-white"
                        @click="logout"
                    >
                        Log out
                    </button>
                </div>
            </div>
        </nav>
    </header>

    <main class="mx-auto max-w-6xl px-6 py-8">
        <div
            v-if="impersonation.active"
            class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            <span>
                Viewing as {{ user?.name }}. Signed in as {{ impersonation.impersonator_name }}.
            </span>
            <button type="button" class="font-medium underline" @click="stopImpersonating">
                Stop impersonating
            </button>
        </div>

        <div
            v-if="flashStatus"
            class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
        >
            {{ flashStatus }}
        </div>

        <slot />
    </main>

    <FeedbackWidget v-if="user" />
</template>
