<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    intent: { type: String, required: true },
    packs: { type: Array, required: true },
    balance: { type: Number, required: true },
    testCards: { type: Array, required: true },
});

const { formatNanos } = useMoney();

const step = ref(1);

const form = useForm({
    // Single-use, issued by the server. Claiming it is what authorises the
    // credit, so a double submission cannot add the money twice.
    intent: props.intent,

    // The marked pack, or the first if none is marked. Read from the data
    // rather than from a position, so the default follows the highlight
    // instead of having to agree with it.
    amount: (props.packs.find((pack) => pack.note) ?? props.packs[0]).amount,
    card_number: '',
    card_expiry: '',
    card_cvc: '',
    card_name: '',
});

const chosen = computed(() => props.packs.find((pack) => pack.amount === form.amount));

const newBalance = computed(() => props.balance + Number(form.amount) * 1_000_000_000);

/** Grouped in fours while typing, which is how card numbers are read. */
function formatCardNumber(event) {
    const digits = event.target.value.replace(/\D/g, '').slice(0, 19);

    form.card_number = digits.replace(/(.{4})/g, '$1 ').trim();
}

function formatExpiry(event) {
    const digits = event.target.value.replace(/\D/g, '').slice(0, 4);

    form.card_expiry = digits.length > 2 ? `${digits.slice(0, 2)} / ${digits.slice(2)}` : digits;
}

function useTestCard(number) {
    form.card_number = number.replace(/(.{4})/g, '$1 ').trim();
    form.card_expiry = '12 / 30';
    form.card_cvc = '123';
    form.card_name ||= 'Demo Customer';
}

function pay() {
    form.post(route('checkout.store'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Buy credits" />

    <AppLayout console="customer">
        <template #actions>
            <Link
                :href="route('generations.index')"
                class="text-xs text-gray-500 hover:text-gray-900"
            >
                Back to studio
            </Link>
        </template>

        <div class="mx-auto max-w-4xl">
            <!-- Said once, plainly, at the top. A simulated payment screen that
                 does not announce itself is a screen someone might enter a real
                 card into. -->
            <div class="mb-6 flex items-start gap-2 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-900 ring-1 ring-blue-200">
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0 text-blue-600"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fill-rule="evenodd"
                        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                        clip-rule="evenodd"
                    />
                </svg>
                <span>
                    <strong class="font-semibold">This is a simulated payment.</strong>
                    No card is charged and no money moves. Use one of the test cards below
                    to see each outcome.
                </span>
            </div>

            <div class="grid gap-6 md:grid-cols-[1fr_18rem]">
                <div class="space-y-4">
                    <!-- Step one -->
                    <section class="rounded-xl bg-white ring-1 ring-gray-200">
                        <header class="flex items-center gap-2 border-b border-gray-100 px-5 py-3">
                            <span
                                class="flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-semibold"
                                :class="step === 1 ? 'bg-indigo-600 text-white' : 'bg-emerald-100 text-emerald-700'"
                            >
                                {{ step === 1 ? '1' : '✓' }}
                            </span>
                            <h2 class="text-sm font-semibold text-gray-900">
                                Choose an amount
                            </h2>
                            <button
                                v-if="step === 2"
                                type="button"
                                class="ml-auto text-xs text-indigo-600 hover:text-indigo-500"
                                @click="step = 1"
                            >
                                Change
                            </button>
                        </header>

                        <div
                            v-if="step === 1"
                            class="space-y-4 p-5"
                        >
                            <div class="grid grid-cols-3 gap-3">
                                <button
                                    v-for="pack in packs"
                                    :key="pack.amount"
                                    type="button"
                                    class="relative rounded-lg border px-3 py-4 text-center transition"
                                    :class="pack.amount === form.amount
                                        ? 'border-indigo-600 bg-indigo-50 ring-1 ring-indigo-600'
                                        : 'border-gray-200 hover:border-gray-300'"
                                    @click="form.amount = pack.amount"
                                >
                                    <span
                                        v-if="pack.note"
                                        class="absolute -top-2 left-1/2 -translate-x-1/2 rounded-full bg-indigo-600 px-2 py-0.5 text-[10px] font-semibold text-white"
                                    >
                                        {{ pack.note }}
                                    </span>
                                    <span class="block text-lg font-semibold text-gray-900">{{ pack.label }}</span>
                                </button>
                            </div>

                            <InputError :message="form.errors.amount" />

                            <button
                                type="button"
                                class="w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-800"
                                @click="step = 2"
                            >
                                Continue
                            </button>
                        </div>

                        <p
                            v-else
                            class="px-5 py-3 text-sm text-gray-600"
                        >
                            {{ chosen.label }} of credit
                        </p>
                    </section>

                    <!-- Step two -->
                    <section
                        class="rounded-xl bg-white ring-1 ring-gray-200"
                        :class="{ 'opacity-50': step === 1 }"
                    >
                        <header class="flex items-center gap-2 border-b border-gray-100 px-5 py-3">
                            <span
                                class="flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-semibold"
                                :class="step === 2 ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-500'"
                            >2</span>
                            <h2 class="text-sm font-semibold text-gray-900">
                                Card details
                            </h2>
                        </header>

                        <form
                            v-if="step === 2"
                            class="space-y-4 p-5"
                            @submit.prevent="pay"
                        >
                            <div>
                                <label
                                    for="card_name"
                                    class="text-xs font-medium uppercase tracking-wide text-gray-500"
                                >Name on card</label>
                                <input
                                    id="card_name"
                                    v-model="form.card_name"
                                    type="text"
                                    autocomplete="off"
                                    class="mt-1.5 block w-full rounded-lg border-gray-200 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Demo Customer"
                                >
                                <InputError
                                    class="mt-1"
                                    :message="form.errors.card_name"
                                />
                            </div>

                            <div>
                                <label
                                    for="card_number"
                                    class="text-xs font-medium uppercase tracking-wide text-gray-500"
                                >Card number</label>
                                <input
                                    id="card_number"
                                    :value="form.card_number"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    class="mt-1.5 block w-full rounded-lg border-gray-200 font-mono text-sm tabular-nums shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="4242 4242 4242 4242"
                                    @input="formatCardNumber"
                                >
                                <InputError
                                    class="mt-1"
                                    :message="form.errors.card_number"
                                />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label
                                        for="card_expiry"
                                        class="text-xs font-medium uppercase tracking-wide text-gray-500"
                                    >Expiry</label>
                                    <input
                                        id="card_expiry"
                                        :value="form.card_expiry"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        class="mt-1.5 block w-full rounded-lg border-gray-200 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="MM / YY"
                                        @input="formatExpiry"
                                    >
                                    <InputError
                                        class="mt-1"
                                        :message="form.errors.card_expiry"
                                    />
                                </div>
                                <div>
                                    <label
                                        for="card_cvc"
                                        class="text-xs font-medium uppercase tracking-wide text-gray-500"
                                    >CVC</label>
                                    <input
                                        id="card_cvc"
                                        v-model="form.card_cvc"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="4"
                                        autocomplete="off"
                                        class="mt-1.5 block w-full rounded-lg border-gray-200 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="123"
                                    >
                                    <InputError
                                        class="mt-1"
                                        :message="form.errors.card_cvc"
                                    />
                                </div>
                            </div>

                            <InputError :message="form.errors.intent" />

                            <button
                                type="submit"
                                class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-300"
                                :disabled="form.processing"
                            >
                                {{ form.processing ? 'Authorising…' : `Pay ${chosen.label}` }}
                            </button>

                            <p class="text-center text-[11px] text-gray-400">
                                Simulated. Nothing is sent to a payment provider.
                            </p>
                        </form>
                    </section>
                </div>

                <aside class="space-y-4">
                    <div class="rounded-xl bg-white p-5 ring-1 ring-gray-200">
                        <h3 class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Summary
                        </h3>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">
                                    Balance now
                                </dt>
                                <dd class="tabular-nums text-gray-900">
                                    {{ formatNanos(balance) }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">
                                    Adding
                                </dt>
                                <dd class="tabular-nums text-gray-900">
                                    {{ chosen.label }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-t border-gray-100 pt-2 font-semibold">
                                <dt class="text-gray-900">
                                    After payment
                                </dt>
                                <dd class="tabular-nums text-gray-900">
                                    {{ formatNanos(newBalance) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-xl bg-white p-5 ring-1 ring-gray-200">
                        <h3 class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Test cards
                        </h3>
                        <p class="mt-1 text-[11px] text-gray-400">
                            Each number always produces the same outcome, so a failure is
                            reproducible rather than a coin toss.
                        </p>
                        <ul class="mt-3 space-y-1.5">
                            <li
                                v-for="card in testCards"
                                :key="card.number"
                            >
                                <button
                                    type="button"
                                    class="w-full rounded-md px-2 py-1.5 text-left transition hover:bg-gray-50"
                                    @click="useTestCard(card.number); step = 2"
                                >
                                    <span class="block font-mono text-[11px] tabular-nums text-gray-700">
                                        {{ card.number.replace(/(.{4})/g, '$1 ').trim() }}
                                    </span>
                                    <span class="block text-[11px] text-gray-500">{{ card.outcome }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
