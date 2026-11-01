<script setup>
const props = defineProps({
    label: String,
    value: [Number, String],
    icon:  String
})
</script>

<template>
    <div class="ui-panel min-w-0 p-5 sm:p-6">
        <div class="flex items-center justify-between gap-3"><span class="ui-muted text-xs font-semibold sm:text-sm">{{ label }}</span><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-xl text-primary"><i :class="icon" aria-hidden="true" /></span></div>
        <p class="mt-5 truncate text-3xl font-extrabold tracking-tight text-base-content sm:text-4xl" style="font-variant-numeric: tabular-nums">{{ typeof value === 'number' ? value.toLocaleString('ru-RU') : value }}</p>
    </div>
</template>
