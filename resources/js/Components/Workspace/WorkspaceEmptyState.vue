<script setup>
defineProps({ title: { type: String, default: 'Пока ничего нет' }, description: String, icon: { type: String, default: 'mdi-image-outline' } })
</script>

<template>
    <div class="workspace-empty" role="status">
        <span class="workspace-empty-icon" aria-hidden="true"><i :class="['mdi', icon]" /></span>
        <h2 class="mt-5 text-xl">{{ title }}</h2>
        <p v-if="description" class="ui-muted mx-auto mt-3 max-w-md text-sm leading-relaxed">{{ description }}</p>
        <div v-if="$slots.default" class="mt-6 flex flex-wrap justify-center gap-3"><slot /></div>
    </div>
</template>
