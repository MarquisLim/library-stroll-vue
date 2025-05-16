<script setup>
const props = defineProps({
    label: String,
    value: [Number, String],
    icon:  String
})
</script>

<template>
    <div
        class="bg-base-200 dark:bg-base-800 rounded-xl flex flex-col items-center py-6 shadow"
    >
        <div
            class="w-14 h-14 rounded-full border border-primary flex items-center justify-center"
        >
            <i :class="`${icon} text-2xl text-primary`" />
        </div>
        <p class="text-3xl font-extrabold mt-2 text-base-content">
            {{ value }}
        </p>
        <span class="text-sm text-base-content/60">
      {{ label }}
    </span>
    </div>
</template>
