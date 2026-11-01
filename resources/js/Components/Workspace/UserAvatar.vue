<script setup>
import { computed, ref, watch } from 'vue'
const props = defineProps({ src: String, name: String, size: { type: Number, default: 40 } })
const failed = ref(false)
watch(() => props.src, () => { failed.value = false })
const initials = computed(() => (props.name || '?').trim().split(/\s+/).slice(0, 2).map(part => part.charAt(0)).join('').toUpperCase())
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary/10 text-sm font-bold text-primary" :style="{width: size + 'px', height: size + 'px'}" role="img" :aria-label="name || 'Аватар'">
        <img v-if="src && !failed" :src="src" alt="" aria-hidden="true" class="h-full w-full object-cover" @error="failed = true" />
        <span v-else>{{ initials }}</span>
    </span>
</template>
