<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, required: true },
});

/**
 * Colour carries meaning here, so it is never the only thing that does: the
 * label is always present, and the animation on an in-flight badge gives a
 * second, non-colour signal that something is still happening.
 */
const appearance = computed(() => ({
    creating: { label: 'Accepted', classes: 'bg-gray-100 text-gray-700 ring-gray-200', busy: true },
    queued: { label: 'Queued', classes: 'bg-blue-50 text-blue-700 ring-blue-200', busy: true },
    processing: { label: 'Generating', classes: 'bg-amber-50 text-amber-800 ring-amber-200', busy: true },
    succeeded: { label: 'Done', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200', busy: false },
    failed: { label: 'Failed', classes: 'bg-rose-50 text-rose-700 ring-rose-200', busy: false },
}[props.status] ?? { label: props.status, classes: 'bg-gray-100 text-gray-700 ring-gray-200', busy: false }));
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="appearance.classes"
    >
        <span
            v-if="appearance.busy"
            class="h-1.5 w-1.5 animate-pulse rounded-full bg-current"
            aria-hidden="true"
        />
        {{ appearance.label }}
    </span>
</template>
