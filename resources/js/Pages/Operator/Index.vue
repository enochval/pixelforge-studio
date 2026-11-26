<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    days: { type: Number, required: true },
    report: { type: Object, required: true },
});

const ranges = [7, 30, 92];

function show(days) {
    router.get(route('operator.index'), { days }, { preserveScroll: true });
}

/**
 * Amounts arrive as decimal strings the server has already formatted, and are
 * rendered as they are.
 *
 * Deliberately not passed through the browser's money formatter used elsewhere.
 * That one rounds for readability, which is right for a user looking at one
 * generation and wrong here: this page exists so an operator can see whether
 * two figures agree, and a rounded figure can hide a disagreement of exactly
 * the amount being looked for.
 */
const check = computed(() => props.report.reconciliation);
</script>

<template>
    <Head title="Operator report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Operator report
                </h2>
                <Link
                    :href="route('generations.index')"
                    class="text-sm text-indigo-600 hover:text-indigo-500"
                >
                    Back to generations
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-sm text-gray-600">
                        {{ report.from }} to {{ report.to }} <span class="text-gray-400">(UTC)</span>
                    </p>
                    <div class="flex gap-1">
                        <button
                            v-for="range in ranges"
                            :key="range"
                            type="button"
                            class="rounded-md px-2.5 py-1 text-xs font-medium"
                            :class="range === days
                                ? 'bg-gray-900 text-white'
                                : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50'"
                            @click="show(range)"
                        >
                            {{ range }} days
                        </button>
                    </div>
                </div>

                <!--
                    The only figure on this page that can disprove anything.
                    Everything below is a sum of our own records, which will
                    agree with itself whether or not it is right.
                -->
                <section
                    class="overflow-hidden rounded-lg bg-white shadow-sm ring-1"
                    :class="check.available === false
                        ? 'ring-amber-200'
                        : check.agrees ? 'ring-emerald-200' : 'ring-rose-200'"
                >
                    <header class="border-b border-gray-100 px-4 py-3 sm:px-6">
                        <h3 class="text-sm font-medium text-gray-900">
                            Reconciliation against the provider
                        </h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Our record of provider cost, against what <code>GET /v1/usage</code> reports
                        </p>
                    </header>

                    <div
                        v-if="check.available === false"
                        class="px-4 py-4 text-sm text-amber-800 sm:px-6"
                    >
                        <p class="font-medium">
                            Unavailable
                        </p>
                        <p class="mt-1 text-xs">
                            {{ check.reason }} — reported as unavailable rather than as agreement,
                            because no answer and an answer of zero mean opposite things.
                        </p>
                    </div>

                    <div
                        v-else
                        class="px-4 py-4 sm:px-6"
                    >
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <dt class="text-xs text-gray-500">
                                    Recorded here
                                </dt>
                                <dd class="mt-0.5 font-mono text-sm tabular-nums text-gray-900">
                                    ${{ check.recorded_usd }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">
                                    Provider reports
                                </dt>
                                <dd class="mt-0.5 font-mono text-sm tabular-nums text-gray-900">
                                    ${{ check.provider_usd }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">
                                    Difference
                                </dt>
                                <dd
                                    class="mt-0.5 font-mono text-sm tabular-nums"
                                    :class="check.agrees ? 'text-emerald-700' : 'text-rose-700'"
                                >
                                    ${{ check.difference_usd }}
                                </dd>
                            </div>
                        </dl>

                        <p
                            class="mt-3 text-xs"
                            :class="check.agrees ? 'text-emerald-700' : 'text-rose-700'"
                        >
                            <template v-if="check.agrees">
                                Records agree with the provider exactly.
                            </template>
                            <template v-else>
                                Records differ. A generation completing either side of midnight UTC
                                can explain a small daily difference; a total that does not close cannot.
                            </template>
                        </p>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <header class="flex items-baseline justify-between border-b border-gray-100 px-4 py-3 sm:px-6">
                        <h3 class="text-sm font-medium text-gray-900">
                            By day
                        </h3>
                        <p class="text-xs text-gray-500">
                            {{ report.totals.generations }} generation(s) ·
                            charged <span class="font-mono">${{ report.totals.charged_usd }}</span> ·
                            profit <span class="font-mono">${{ report.totals.profit_usd }}</span>
                        </p>
                    </header>

                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-left font-medium sm:px-6"
                                >
                                    Day
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium"
                                >
                                    Generations
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium"
                                >
                                    Provider cost
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium"
                                >
                                    Charged
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium sm:px-6"
                                >
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="report.by_day.length === 0">
                                <td
                                    colspan="5"
                                    class="px-4 py-6 text-center text-gray-500 sm:px-6"
                                >
                                    Nothing in this range.
                                </td>
                            </tr>
                            <tr
                                v-for="row in report.by_day"
                                :key="row.day"
                            >
                                <td class="px-4 py-2 text-gray-900 sm:px-6">
                                    {{ row.day }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-600">
                                    {{ row.generations }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-600">
                                    ${{ row.provider_cost_usd }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">
                                    ${{ row.charged_usd }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-emerald-700 sm:px-6">
                                    ${{ row.profit_usd }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <header class="border-b border-gray-100 px-4 py-3 sm:px-6">
                        <h3 class="text-sm font-medium text-gray-900">
                            By user
                        </h3>
                    </header>

                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-left font-medium sm:px-6"
                                >
                                    User
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium"
                                >
                                    Generations
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium"
                                >
                                    Provider cost
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium"
                                >
                                    Charged
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium sm:px-6"
                                >
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="report.by_user.length === 0">
                                <td
                                    colspan="5"
                                    class="px-4 py-6 text-center text-gray-500 sm:px-6"
                                >
                                    Nothing in this range.
                                </td>
                            </tr>
                            <tr
                                v-for="row in report.by_user"
                                :key="row.user_id"
                            >
                                <td class="px-4 py-2 text-gray-900 sm:px-6">
                                    {{ row.email }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-600">
                                    {{ row.generations }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-600">
                                    ${{ row.provider_cost_usd }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">
                                    ${{ row.charged_usd }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-emerald-700 sm:px-6">
                                    ${{ row.profit_usd }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
