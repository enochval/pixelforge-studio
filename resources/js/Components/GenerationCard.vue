<script setup>
import { computed } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    generation: { type: Object, required: true },
});

const { formatNanos } = useMoney();

/**
 * What this generation cost, and how settled that figure is.
 *
 * Three genuinely different things rather than three renderings of one number:
 * a final charge, nothing owed because it failed, or only the reserved ceiling
 * because it is still running.
 */
const cost = computed(() => {
    if (props.generation.charged_nanos !== null) {
        return { value: formatNanos(props.generation.charged_nanos), note: 'charged', tone: 'text-gray-900' };
    }

    if (props.generation.status === 'failed') {
        return { value: 'No charge', note: 'failures are never billed', tone: 'text-gray-500' };
    }

    return {
        value: `up to ${formatNanos(props.generation.quoted_nanos)}`,
        note: 'reserved, not charged',
        tone: 'text-gray-500',
    };
});

const busy = computed(() => !props.generation.is_terminal);
</script>

<template>
    <article class="group overflow-hidden rounded-xl bg-white ring-1 ring-gray-200 transition hover:ring-gray-300">
        <div class="relative aspect-square bg-gray-50">
            <a
                v-if="generation.image_url"
                :href="generation.image_url"
                target="_blank"
                rel="noopener"
                class="block h-full w-full"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    class="h-full w-full object-cover"
                    loading="lazy"
                >
            </a>

            <!-- Still running: a shimmer rather than a spinner, because the
                 placeholder occupies the shape the image will take. -->
            <div
                v-else-if="busy"
                class="flex h-full w-full animate-pulse items-center justify-center bg-gradient-to-br from-gray-100 to-gray-200"
            >
                <span class="text-xs font-medium text-gray-400">Generating…</span>
            </div>

            <div
                v-else
                class="flex h-full w-full flex-col items-center justify-center gap-1 px-4 text-center"
            >
                <svg
                    class="h-6 w-6 text-gray-300"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
                    />
                </svg>
                <span class="text-xs text-gray-400">
                    {{ generation.image_url_expired ? 'Image expired' : 'No image' }}
                </span>
            </div>

            <div class="absolute left-2 top-2">
                <StatusBadge :status="generation.status" />
            </div>
        </div>

        <div class="space-y-1.5 p-3">
            <p
                class="line-clamp-2 text-sm text-gray-900"
                :title="generation.prompt"
            >
                {{ generation.prompt }}
            </p>

            <p class="text-[11px] text-gray-400">
                {{ generation.size }} · {{ generation.quality }}
            </p>

            <p
                v-if="generation.failure_message"
                class="line-clamp-2 text-[11px] text-rose-700"
                :title="generation.failure_message"
            >
                {{ generation.failure_message }}
            </p>

            <div class="flex items-baseline justify-between border-t border-gray-100 pt-1.5">
                <span
                    class="text-sm font-medium tabular-nums"
                    :class="cost.tone"
                >{{ cost.value }}</span>
                <span class="text-[11px] text-gray-400">{{ cost.note }}</span>
            </div>
        </div>
    </article>
</template>
