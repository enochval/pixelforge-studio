<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    sizes: { type: Array, required: true },
    qualities: { type: Array, required: true },
});

const { formatNanos } = useMoney();

// Reference images are deliberately not offered here. The API layer sends them,
// validation accepts them and pricing bills them at 765 tokens each, but the
// screen keeps to one decision per field; adding a URL list would be the
// largest part of this form and the smallest part of what it demonstrates.
const form = useForm({
    prompt: '',
    size: props.sizes[0],
    quality: props.qualities.includes('medium') ? 'medium' : props.qualities[0],
});

const quote = ref(null);
const quoting = ref(false);

// The request in flight, so a newer one can cancel it.
let pending = null;

/**
 * Asks the server what this request would cost.
 *
 * The price is never computed here. It comes from the same Pricing class that
 * performs the charge, so the figure a user agrees to and the figure they are
 * billed cannot drift apart — a rate table in JavaScript would be a second
 * source of truth for the one value in this system that most needs a single
 * one.
 */
async function refreshQuote() {
    if (form.prompt.trim() === '') {
        quote.value = null;

        return;
    }

    // Each keystroke supersedes the last request. Without this, answers can
    // arrive out of order and the price shown ends up belonging to a prompt the
    // user has already changed.
    pending?.abort();
    pending = new AbortController();

    quoting.value = true;

    try {
        // axios, not fetch: Breeze configures it to send Laravel's XSRF cookie
        // as a header automatically, so the quote route stays inside CSRF
        // protection without a token being read out of the page by hand.
        const { data } = await window.axios.post(
            route('generations.quote'),
            {
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
            },
            { signal: pending.signal },
        );

        quote.value = data;
    } catch (error) {
        // A cancelled request is the expected outcome of typing, not a failure,
        // and a 422 means the request is not yet priceable — neither is worth
        // showing as an error, because the form already reports invalid fields.
        if (!window.axios.isCancel(error)) {
            quote.value = null;
        }
    } finally {
        quoting.value = false;
    }
}

let debounce = null;

watch(
    () => [form.prompt, form.size, form.quality],
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(refreshQuote, 300);
    },
);

const quoteLabel = computed(() => {
    if (form.prompt.trim() === '') {
        return 'Enter a prompt to see what it will cost';
    }

    if (quote.value === null) {
        return quoting.value ? 'Pricing…' : '—';
    }

    // Stated as a ceiling because that is what it is: two of the four token
    // counts a generation is billed on are only known once it completes.
    return `Up to ${formatNanos(quote.value.nanos)}`;
});

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
    <form
        class="space-y-4"
        @submit.prevent="submit"
    >
        <div>
            <InputLabel
                for="prompt"
                value="Prompt"
            />
            <TextInput
                id="prompt"
                v-model="form.prompt"
                type="text"
                class="mt-1 block w-full"
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                maxlength="4000"
                autocomplete="off"
            />
            <InputError
                class="mt-1"
                :message="form.errors.prompt"
            />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <InputLabel
                    for="size"
                    value="Size"
                />
                <select
                    id="size"
                    v-model="form.size"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option
                        v-for="size in sizes"
                        :key="size"
                        :value="size"
                    >
                        {{ size }}
                    </option>
                </select>
                <InputError
                    class="mt-1"
                    :message="form.errors.size"
                />
            </div>

            <div>
                <InputLabel
                    for="quality"
                    value="Quality"
                />
                <select
                    id="quality"
                    v-model="form.quality"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option
                        v-for="quality in qualities"
                        :key="quality"
                        :value="quality"
                    >
                        {{ quality }}
                    </option>
                </select>
                <InputError
                    class="mt-1"
                    :message="form.errors.quality"
                />
            </div>
        </div>

        <div class="flex items-center justify-between gap-4 border-t border-gray-100 pt-4">
            <p
                class="text-sm text-gray-600"
                aria-live="polite"
            >
                {{ quoteLabel }}
            </p>

            <PrimaryButton
                :disabled="form.processing || form.prompt.trim() === ''"
                :class="{ 'opacity-50': form.processing || form.prompt.trim() === '' }"
            >
                {{ form.processing ? 'Submitting…' : 'Generate' }}
            </PrimaryButton>
        </div>
    </form>
</template>
