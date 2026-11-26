<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    // Which application this page belongs to. The shell is shared because the
    // chrome is the same; what differs is the one nav item and the accent.
    console: { type: String, default: 'customer' },
});

const page = computed(() => usePage());
const user = computed(() => page.value.props.auth?.user ?? null);
const flash = computed(() => page.value.props.flash?.status ?? null);

const isOperator = computed(() => props.console === 'operator');

const initials = computed(() => (user.value?.name ?? '?')
    .split(' ')
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase());

function signOut() {
    router.post(route('logout'));
}
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4 sm:px-6 lg:px-8">
                <Link
                    :href="isOperator ? route('operator.index') : route('generations.index')"
                    class="flex items-center gap-2"
                >
                    <!-- A mark rather than a logo file: two overlapping shapes,
                         which is enough to read as a product without pretending
                         to be a brand. -->
                    <span
                        class="flex h-7 w-7 items-center justify-center rounded-md"
                        :class="isOperator ? 'bg-slate-900' : 'bg-indigo-600'"
                    >
                        <svg
                            class="h-4 w-4 text-white"
                            viewBox="0 0 20 20"
                            fill="none"
                            aria-hidden="true"
                        >
                            <rect
                                x="2.5"
                                y="2.5"
                                width="10"
                                height="10"
                                rx="2"
                                stroke="currentColor"
                                stroke-width="1.6"
                            />
                            <rect
                                x="7.5"
                                y="7.5"
                                width="10"
                                height="10"
                                rx="2"
                                fill="currentColor"
                                opacity="0.85"
                            />
                        </svg>
                    </span>
                    <span class="text-sm font-semibold tracking-tight text-gray-900">
                        Pixelforge
                        <span class="font-normal text-gray-400">{{ isOperator ? 'Console' : 'Studio' }}</span>
                    </span>
                </Link>

                <span
                    v-if="isOperator"
                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600"
                >
                    Operator
                </span>

                <div class="ml-auto flex items-center gap-3">
                    <slot name="actions" />

                    <div
                        v-if="user"
                        class="flex items-center gap-2 border-l border-gray-200 pl-3"
                    >
                        <span
                            class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-[11px] font-semibold text-gray-600"
                            :title="user.email"
                        >
                            {{ initials }}
                        </span>
                        <button
                            type="button"
                            class="text-xs text-gray-500 hover:text-gray-900"
                            @click="signOut"
                        >
                            Sign out
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Flash messages live in the shell so any page can raise one without
             its own markup for it. -->
        <div
            v-if="flash"
            class="mx-auto max-w-6xl px-4 pt-4 sm:px-6 lg:px-8"
        >
            <div
                class="flex items-start gap-2 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200"
                role="status"
            >
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                        clip-rule="evenodd"
                    />
                </svg>
                <span>{{ flash }}</span>
            </div>
        </div>

        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
            <div
                v-if="$slots.heading"
                class="mb-6"
            >
                <slot name="heading" />
            </div>

            <slot />
        </main>
    </div>
</template>
