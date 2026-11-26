<script setup>
import { computed } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    generation: { type: Object, required: true },
});

const { formatNanos } = useMoney();

/**
 * What this generation cost, and how certain that figure is.
 *
 * Three states, and they are genuinely different things rather than three
 * renderings of one number:
 *
 *   A charge exists, so the figure is exact and final.
 *   The generation failed, so there is nothing to pay. Said explicitly rather
 *   than shown as $0.00, which would read like a missing value.
 *   It is still running, so only the quoted ceiling is known.
 */
const cost = computed(() => {
    if (props.generation.charged_nanos !== null) {
        return { label: formatNanos(props.generation.charged_nanos), note: 'charged' };
    }

    if (props.generation.status === 'failed') {
        return { label: 'No charge', note: 'failed generations are not billed' };
    }

    return { label: `up to ${formatNanos(props.generation.quoted_nanos)}`, note: 'not yet charged' };
});
</script>

<template>
    <li class="flex gap-4 px-4 py-4 sm:px-6">
        <div class="shrink-0">
            <a
                v-if="generation.image_url"
                :href="generation.image_url"
                target="_blank"
                rel="noopener"
                class="block"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    class="h-16 w-16 rounded-md object-cover ring-1 ring-gray-200"
                    loading="lazy"
                >
            </a>
            <div
                v-else
                class="flex h-16 w-16 items-center justify-center rounded-md bg-gray-50 text-xs text-gray-400 ring-1 ring-gray-200"
            >
                {{ generation.status === 'failed' ? '—' : '…' }}
            </div>
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
                <p class="truncate text-sm font-medium text-gray-900">
                    {{ generation.prompt }}
                </p>
                <StatusBadge :status="generation.status" />
            </div>

            <p class="mt-1 text-xs text-gray-500">
                {{ generation.size }} · {{ generation.quality }}
            </p>

            <p
                v-if="generation.failure_message"
                class="mt-1 text-xs text-rose-700"
            >
                {{ generation.failure_message }}
            </p>
        </div>

        <div class="shrink-0 text-right">
            <p class="text-sm font-medium tabular-nums text-gray-900">
                {{ cost.label }}
            </p>
            <p class="text-xs text-gray-500">
                {{ cost.note }}
            </p>
        </div>
    </li>
</template>
