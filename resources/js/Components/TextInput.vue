<script setup>
import { onMounted, ref, useAttrs } from 'vue'

defineOptions({
    inheritAttrs: false,
})

defineProps({
    modelValue: String,
})

defineEmits(['update:modelValue'])

const input = ref(null)
const attrs = useAttrs()

onMounted(() => {
    if (attrs.autofocus !== undefined) {
        input.value?.focus()
    }
})

defineExpose({
    focus: () => input.value?.focus(),
})
</script>

<template>
    <input
        ref="input"
        v-bind="attrs"
        class="input input-bordered w-full"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
