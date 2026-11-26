<script setup>
import { computed, ref, toRef, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import GenerationCard from '@/Components/GenerationCard.vue';
import InputError from '@/Components/InputError.vue';
import OptionCards from '@/Components/OptionCards.vue';
import { useGenerationPolling } from '@/composables/useGenerationPolling';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    sizes: { type: Array, required: true },
    qualities: { type: Array, required: true },
    balance: { type: Number, required: true },
    generations: { type: Array, required: true },
});

const { formatNanos } = useMoney();

// Refreshes the grid while anything is still running, and stops once nothing
// is. See useGenerationPolling for why this is a partial reload.
useGenerationPolling(toRef(props, 'generations'));

const form = useForm({
    prompt: '',
    size: props.sizes.includes('1024x1024') ? '1024x1024' : props.sizes[0],
    quality: props.qualities.includes('medium') ? 'medium' : props.qualities[0],
});

// Shape and detail, said in the terms someone choosing would use rather than
// the provider's own labels.
const sizeOptions = computed(() => props.sizes.map((size) => ({
    value: size,
    label: size === '1024x1024' ? 'Square' : size === '1536x1024' ? 'Landscape' : 'Portrait',
    note: size,
})));

const qualityOptions = computed(() => props.qualities.map((quality) => ({
    value: quality,
    label: quality.charAt(0).toUpperCase() + quality.slice(1),
    note: { low: 'fastest', medium: 'balanced', high: 'most detail' }[quality] ?? null,
})));

const quote = ref(null);
const quoting = ref(false);

// The request in flight, so a newer one can cancel it.
let pending = null;

/**
 * Asks the server what this request would cost.
 *
 * The price is never computed here. It comes from the same Pricing class that
 * performs the charge, so the figure a user agrees to and the figure they are
 * billed cannot drift apart.
 */
async function refreshQuote() {
    if (form.prompt.trim() === '') {
        quote.value = null;

        return;
    }

    // Each change supersedes the last request. Without this, answers can
    // arrive out of order and the price shown belongs to a prompt already
    // changed.
    pending?.abort();
    pending = new AbortController();

    quoting.value = true;

    try {
        const { data } = await window.axios.post(
            route('generations.quote'),
            { prompt: form.prompt, size: form.size, quality: form.quality },
            { signal: pending.signal },
        );

        quote.value = data.nanos;
    } catch (error) {
        // A cancelled request is the expected outcome of typing, and a 422
        // means the request is not yet priceable. Neither is worth surfacing:
        // the form already reports invalid fields.
        if (!window.axios.isCancel(error)) {
            quote.value = null;
        }
    } finally {
        quoting.value = false;
    }
}

let debounce = null;

watch(() => [form.prompt, form.size, form.quality], () => {
    clearTimeout(debounce);
    debounce = setTimeout(refreshQuote, 300);
});

const affordable = computed(() => quote.value === null || quote.value <= props.balance);
const ready = computed(() => form.prompt.trim() !== '' && affordable.value && !form.processing);

const inFlight = computed(() => props.generations.filter((generation) => !generation.is_terminal).length);

function submit() {
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt');
            quote.value = null;
        },
    });
}
</script>

<template>
    <Head title="Studio" />

    <AppLayout console="customer">
        <template #actions>
            <div class="flex items-center gap-3">
                <div class="text-right">
                    <p class="text-[10px] uppercase tracking-wide text-gray-400">
                        Balance
                    </p>
                    <p class="text-sm font-semibold tabular-nums leading-tight text-gray-900">
                        {{ formatNanos(balance) }}
                    </p>
                </div>
                <Link
                    :href="route('checkout.create')"
                    class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-500"
                >
                    Buy credits
                </Link>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
            <!-- The form stays put while the grid refreshes beside it, so
                 polling never disturbs what is being typed. -->
            <aside class="lg:sticky lg:top-6 lg:self-start">
                <form
                    class="space-y-5 rounded-xl bg-white p-5 ring-1 ring-gray-200"
                    @submit.prevent="submit"
                >
                    <div>
                        <h1 class="text-base font-semibold text-gray-900">
                            Generate an image
                        </h1>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Describe what you want. The price updates as you type.
                        </p>
                    </div>

                    <div>
                        <label
                            for="prompt"
                            class="text-xs font-medium uppercase tracking-wide text-gray-500"
                        >
                            Prompt
                        </label>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="4"
                            maxlength="4000"
                            class="mt-2 block w-full resize-none rounded-lg border-gray-200 text-sm shadow-sm placeholder:text-gray-400 focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                        />
                        <div class="mt-1 flex items-start justify-between gap-2">
                            <InputError :message="form.errors.prompt" />
                            <span class="ml-auto shrink-0 text-[11px] text-gray-400">
                                {{ form.prompt.length }} / 4000
                            </span>
                        </div>
                    </div>

                    <OptionCards
                        v-model="form.size"
                        label="Shape"
                        :options="sizeOptions"
                    />
                    <OptionCards
                        v-model="form.quality"
                        label="Detail"
                        :options="qualityOptions"
                        hint="affects price"
                    />

                    <!-- Stated as a ceiling because that is what it is: two of
                         the four token counts a generation is billed on are
                         only known once it completes. -->
                    <div class="rounded-lg bg-gray-50 p-3">
                        <div class="flex items-baseline justify-between">
                            <span class="text-xs text-gray-500">Cost</span>
                            <span
                                class="text-sm font-semibold tabular-nums text-gray-900"
                                aria-live="polite"
                            >
                                <template v-if="form.prompt.trim() === ''">—</template>
                                <template v-else-if="quote === null">{{ quoting ? 'Pricing…' : '—' }}</template>
                                <template v-else>up to {{ formatNanos(quote) }}</template>
                            </span>
                        </div>
                        <p class="mt-1 text-[11px] leading-snug text-gray-400">
                            A ceiling, not an estimate. You are charged the actual cost
                            once the image is finished, and nothing at all if it fails.
                        </p>
                    </div>

                    <div
                        v-if="quote !== null && !affordable"
                        class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 ring-1 ring-amber-200"
                    >
                        Your balance is {{ formatNanos(balance) }}, which will not cover this.
                        <Link
                            :href="route('checkout.create')"
                            class="font-semibold underline"
                        >
                            Add credit
                        </Link>
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-300"
                        :disabled="!ready"
                    >
                        {{ form.processing ? 'Submitting…' : 'Generate' }}
                    </button>
                </form>
            </aside>

            <section>
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">
                        Your images
                    </h2>
                    <p
                        v-if="inFlight > 0"
                        class="flex items-center gap-1.5 text-xs text-gray-500"
                        aria-live="polite"
                    >
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500" />
                        {{ inFlight }} in progress
                    </p>
                </div>

                <div
                    v-if="generations.length === 0"
                    class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 px-6 py-20 text-center"
                >
                    <svg
                        class="h-8 w-8 text-gray-300"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"
                        />
                    </svg>
                    <p class="mt-3 text-sm font-medium text-gray-900">
                        Nothing generated yet
                    </p>
                    <p class="mt-1 max-w-xs text-xs text-gray-500">
                        Describe an image on the left and it will appear here. Generations
                        take between five and thirty seconds.
                    </p>
                </div>

                <div
                    v-else
                    class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <GenerationCard
                        v-for="generation in generations"
                        :key="generation.id"
                        :generation="generation"
                    />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
