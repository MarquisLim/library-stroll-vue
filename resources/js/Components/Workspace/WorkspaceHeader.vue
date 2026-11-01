<script setup>
defineProps({ eyebrow: String, title: String, description: String, count: [Number, String] })
</script>

<template>
    <header class="workspace-header">
        <div class="min-w-0">
            <p class="ui-eyebrow">{{ eyebrow }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <h1 class="text-3xl sm:text-4xl">{{ title }}</h1>
                <span v-if="count !== undefined && count !== null" class="workspace-count">{{ count }}</span>
            </div>
            <p v-if="description" class="ui-muted mt-3 max-w-2xl text-sm leading-relaxed sm:text-base">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2"><slot name="actions" /></div>
    </header>
</template>
