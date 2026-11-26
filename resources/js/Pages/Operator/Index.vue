<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    days: { type: Number, required: true },
    report: { type: Object, required: true },
});

const ranges = [7, 30, 92];

function show(days) {
    router.get(route('operator.index'), { days }, { preserveScroll: true, preserveState: true });
}

const check = computed(() => props.report.reconciliation);
const totals = computed(() => props.report.totals);

/**
 * Figures are rendered as the decimal strings the server computed.
 *
 * Deliberately not passed through the browser's money formatter used in the
 * customer application. That one rounds for readability, which is right for
 * someone looking at one generation and wrong here: this console exists so an
 * operator can see whether two numbers agree, and a rounded number can hide a
 * disagreement of exactly the size being looked for.
 */
const margin = computed(() => {
    const charged = Number(totals.value.charged_nanos);

    if (charged === 0) {
        return null;
    }

    return ((Number(totals.value.profit_nanos) / charged) * 100).toFixed(1);
});
</script>

<template>
    <Head title="Operator console" />

    <AppLayout console="operator">
        <template #actions>
            <div
                class="flex gap-1"
                role="group"
                aria-label="Date range"
            >
                <button
                    v-for="range in ranges"
                    :key="range"
                    type="button"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                    :class="range === days
                        ? 'bg-slate-900 text-white'
                        : 'text-gray-600 hover:bg-gray-100'"
                    @click="show(range)"
                >
                    {{ range }}d
                </button>
            </div>
        </template>

        <template #heading>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-lg font-semibold tracking-tight text-gray-900">
                    Revenue and reconciliation
                </h1>
                <p class="text-xs text-gray-500">
                    {{ report.from }} to {{ report.to }} <span class="text-gray-400">UTC</span>
                </p>
            </div>
        </template>

        <div class="space-y-6">
            <!--
                First, and largest. The only figure in this console that can
                disprove anything: everything below is a sum of our own records,
                which will agree with itself whether or not it is right.
            -->
            <section
                class="overflow-hidden rounded-xl bg-white ring-1"
                :class="check.available === false
                    ? 'ring-amber-200'
                    : check.agrees ? 'ring-emerald-200' : 'ring-rose-300'"
            >
                <div
                    class="flex items-center gap-2 px-5 py-3"
                    :class="check.available === false
                        ? 'bg-amber-50'
                        : check.agrees ? 'bg-emerald-50' : 'bg-rose-50'"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="check.available === false
                            ? 'bg-amber-500'
                            : check.agrees ? 'bg-emerald-500' : 'bg-rose-500'"
                    />
                    <h2
                        class="text-sm font-semibold"
                        :class="check.available === false
                            ? 'text-amber-900'
                            : check.agrees ? 'text-emerald-900' : 'text-rose-900'"
                    >
                        <template v-if="check.available === false">
                            Reconciliation unavailable
                        </template>
                        <template v-else-if="check.agrees">
                            Our records match the provider exactly
                        </template>
                        <template v-else>
                            Our records differ from the provider
                        </template>
                    </h2>
                    <code class="ml-auto text-[11px] text-gray-500">GET /v1/usage</code>
                </div>

                <div
                    v-if="check.available === false"
                    class="px-5 py-4"
                >
                    <p class="text-sm text-gray-700">
                        {{ check.reason }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        Reported as unavailable rather than as agreement. No answer and an
                        answer of zero mean opposite things, and treating one as the other
                        would turn a broken check into a passing one.
                    </p>
                </div>

                <div
                    v-else
                    class="grid gap-px bg-gray-100 sm:grid-cols-3"
                >
                    <div class="bg-white px-5 py-4">
                        <p class="text-[11px] uppercase tracking-wide text-gray-500">
                            Recorded here
                        </p>
                        <p class="mt-1 font-mono text-lg tabular-nums text-gray-900">
                            ${{ check.recorded_usd }}
                        </p>
                    </div>
                    <div class="bg-white px-5 py-4">
                        <p class="text-[11px] uppercase tracking-wide text-gray-500">
                            Provider reports
                        </p>
                        <p class="mt-1 font-mono text-lg tabular-nums text-gray-900">
                            ${{ check.provider_usd }}
                        </p>
                    </div>
                    <div class="bg-white px-5 py-4">
                        <p class="text-[11px] uppercase tracking-wide text-gray-500">
                            Difference
                        </p>
                        <p
                            class="mt-1 font-mono text-lg tabular-nums"
                            :class="check.agrees ? 'text-emerald-700' : 'text-rose-700'"
                        >
                            ${{ check.difference_usd }}
                        </p>
                    </div>
                </div>

                <p
                    v-if="check.available !== false && !check.agrees"
                    class="border-t border-gray-100 px-5 py-3 text-xs text-gray-600"
                >
                    A generation completing either side of midnight UTC can explain a small
                    daily difference, because the provider bills on the day a generation
                    finished. A total that does not close cannot be explained that way.
                </p>
            </section>

            <!-- Totals -->
            <section class="grid gap-4 sm:grid-cols-4">
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200">
                    <p class="text-[11px] uppercase tracking-wide text-gray-500">
                        Generations
                    </p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900">
                        {{ totals.generations }}
                    </p>
                </div>
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200">
                    <p class="text-[11px] uppercase tracking-wide text-gray-500">
                        Charged to users
                    </p>
                    <p class="mt-1 font-mono text-xl tabular-nums text-gray-900">
                        ${{ totals.charged_usd }}
                    </p>
                </div>
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200">
                    <p class="text-[11px] uppercase tracking-wide text-gray-500">
                        Provider cost
                    </p>
                    <p class="mt-1 font-mono text-xl tabular-nums text-gray-900">
                        ${{ totals.provider_cost_usd }}
                    </p>
                </div>
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200">
                    <p class="text-[11px] uppercase tracking-wide text-gray-500">
                        Profit
                    </p>
                    <p class="mt-1 font-mono text-xl tabular-nums text-emerald-700">
                        ${{ totals.profit_usd }}
                    </p>
                    <p
                        v-if="margin"
                        class="mt-0.5 text-[11px] text-gray-400"
                    >
                        {{ margin }}% margin
                    </p>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="overflow-hidden rounded-xl bg-white ring-1 ring-gray-200">
                    <header class="border-b border-gray-100 px-5 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">
                            By day
                        </h2>
                        <p class="mt-0.5 text-[11px] text-gray-500">
                            Grouped on the day a generation completed, which is the day the
                            provider bills on
                        </p>
                    </header>

                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-5 py-2 text-left font-medium"
                                >
                                    Day
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2 text-right font-medium"
                                >
                                    Gens
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2 text-right font-medium"
                                >
                                    Cost
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2 text-right font-medium"
                                >
                                    Charged
                                </th>
                                <th
                                    scope="col"
                                    class="px-5 py-2 text-right font-medium"
                                >
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="report.by_day.length === 0">
                                <td
                                    colspan="5"
                                    class="px-5 py-10 text-center text-sm text-gray-500"
                                >
                                    No completed generations in this range.
                                </td>
                            </tr>
                            <tr
                                v-for="row in report.by_day"
                                :key="row.day"
                                class="hover:bg-gray-50"
                            >
                                <td class="px-5 py-2 text-gray-900">
                                    {{ row.day }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500">
                                    {{ row.generations }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums text-gray-500">
                                    ${{ row.provider_cost_usd }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums text-gray-900">
                                    ${{ row.charged_usd }}
                                </td>
                                <td class="px-5 py-2 text-right font-mono text-xs tabular-nums text-emerald-700">
                                    ${{ row.profit_usd }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="overflow-hidden rounded-xl bg-white ring-1 ring-gray-200">
                    <header class="border-b border-gray-100 px-5 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">
                            By user
                        </h2>
                        <p class="mt-0.5 text-[11px] text-gray-500">
                            Highest spend first
                        </p>
                    </header>

                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-5 py-2 text-left font-medium"
                                >
                                    User
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2 text-right font-medium"
                                >
                                    Gens
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2 text-right font-medium"
                                >
                                    Charged
                                </th>
                                <th
                                    scope="col"
                                    class="px-5 py-2 text-right font-medium"
                                >
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="report.by_user.length === 0">
                                <td
                                    colspan="4"
                                    class="px-5 py-10 text-center text-sm text-gray-500"
                                >
                                    No spend in this range.
                                </td>
                            </tr>
                            <tr
                                v-for="row in report.by_user"
                                :key="row.user_id"
                                class="hover:bg-gray-50"
                            >
                                <td class="max-w-[14rem] truncate px-5 py-2 text-gray-900">
                                    {{ row.email }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500">
                                    {{ row.generations }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums text-gray-900">
                                    ${{ row.charged_usd }}
                                </td>
                                <td class="px-5 py-2 text-right font-mono text-xs tabular-nums text-emerald-700">
                                    ${{ row.profit_usd }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
