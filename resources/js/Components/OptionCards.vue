<script setup>
defineProps({
    label: { type: String, required: true },
    options: { type: Array, required: true },
    modelValue: { type: String, required: true },
    hint: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <fieldset>
        <div class="flex items-baseline justify-between">
            <legend class="text-xs font-medium uppercase tracking-wide text-gray-500">
                {{ label }}
            </legend>
            <span
                v-if="hint"
                class="text-xs text-gray-400"
            >{{ hint }}</span>
        </div>

        <!--
            Buttons rather than a <select>. There are three of each and the
            choice changes the price, so showing all of them at once means the
            cost of a choice is visible before it is made rather than after.
        -->
        <div class="mt-2 grid grid-cols-3 gap-2">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                class="rounded-lg border px-3 py-2 text-center transition"
                :class="option.value === modelValue
                    ? 'border-indigo-600 bg-indigo-50 ring-1 ring-indigo-600'
                    : 'border-gray-200 bg-white hover:border-gray-300'"
                :aria-pressed="option.value === modelValue"
                @click="$emit('update:modelValue', option.value)"
            >
                <span
                    class="block text-sm font-medium"
                    :class="option.value === modelValue ? 'text-indigo-900' : 'text-gray-900'"
                >
                    {{ option.label }}
                </span>
                <span
                    v-if="option.note"
                    class="mt-0.5 block text-[11px]"
                    :class="option.value === modelValue ? 'text-indigo-700' : 'text-gray-500'"
                >
                    {{ option.note }}
                </span>
            </button>
        </div>
    </fieldset>
</template>
