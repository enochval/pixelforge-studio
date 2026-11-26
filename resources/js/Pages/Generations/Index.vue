<script setup>
import { computed, toRef } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BuyCreditsButton from '@/Components/BuyCreditsButton.vue';
import GenerationForm from '@/Components/GenerationForm.vue';
import GenerationRow from '@/Components/GenerationRow.vue';
import { useGenerationPolling } from '@/composables/useGenerationPolling';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    sizes: { type: Array, required: true },
    qualities: { type: Array, required: true },
    balance: { type: Number, required: true },
    creditPacks: { type: Array, required: true },
    isOperator: { type: Boolean, default: false },
    generations: { type: Array, required: true },
});

const { formatNanos } = useMoney();

// Refreshes the list while anything is still running, and stops once nothing
// is. See useGenerationPolling for why this is a partial reload rather than a
// websocket.
useGenerationPolling(toRef(props, 'generations'));

const inFlight = computed(() => props.generations.filter((generation) => !generation.is_terminal).length);

const flash = computed(() => usePage().props.flash?.status ?? null);
</script>

<template>
    <Head title="Generations" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Generations
                </h2>
                <div class="flex items-center gap-3">
                    <p class="text-sm text-gray-600">
                        Balance
                        <span class="font-medium tabular-nums text-gray-900">{{ formatNanos(balance) }}</span>
                    </p>
                    <BuyCreditsButton :packs="creditPacks" />
                    <Link
                        v-if="isOperator"
                        :href="route('operator.index')"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        Operator report
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                <p
                    v-if="flash"
                    class="rounded-md bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200"
                    role="status"
                >
                    {{ flash }}
                </p>

                <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-4 sm:p-6">
                        <GenerationForm
                            :sizes="sizes"
                            :qualities="qualities"
                        />
                    </div>
                </section>

                <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3 sm:px-6">
                        <h3 class="text-sm font-medium text-gray-900">
                            History
                        </h3>
                        <p
                            v-if="inFlight > 0"
                            class="text-xs text-gray-500"
                            aria-live="polite"
                        >
                            {{ inFlight }} in progress
                        </p>
                    </header>

                    <p
                        v-if="generations.length === 0"
                        class="px-4 py-10 text-center text-sm text-gray-500 sm:px-6"
                    >
                        Nothing generated yet.
                    </p>

                    <ul
                        v-else
                        class="divide-y divide-gray-100"
                    >
                        <GenerationRow
                            v-for="generation in generations"
                            :key="generation.id"
                            :generation="generation"
                        />
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
