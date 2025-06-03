<script setup>
import { onMounted, ref } from 'vue';

defineProps({
    modelValue: String,
});

defineEmits(['update:modelValue']);

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        type="text"
        class="form-input w-full rounded-md"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
        autofocus
    />
</template>
