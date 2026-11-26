<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    packs: { type: Array, required: true },
});

const { formatNanos } = useMoney();

const open = ref(false);

// The amount is one of a fixed server-side list. Sending a chosen figure would
// let a client name the amount of money it receives.
const form = useForm({ amount: props.packs[0] });

function buy(amount) {
    form.amount = amount;

    form.post(route('credits.store'), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50"
            @click="open = !open"
        >
            Buy credits
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-10 mt-2 w-56 rounded-md bg-white p-2 shadow-lg ring-1 ring-black/5"
        >
            <p class="px-2 py-1 text-xs text-gray-500">
                A stand-in for a payment flow. No card is charged.
            </p>

            <button
                v-for="pack in packs"
                :key="pack"
                type="button"
                class="block w-full rounded px-2 py-1.5 text-left text-sm text-gray-900 hover:bg-gray-50 disabled:opacity-50"
                :disabled="form.processing"
                @click="buy(pack)"
            >
                Add {{ formatNanos(Number(pack) * 1_000_000_000) }}
            </button>
        </div>
    </div>
</template>
