<template>
    <label class="flex items-center cursor-pointer space-x-2">
        <input
            type="radio"
            :value="value"
            v-model="model"
            class="form-radio text-primary focus:ring-primary"
        />
        <span class="text-base-content">{{ label }}</span>
    </label>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    label     : String,
    value     : [String, Number],
    modelValue: [String, Number]
})
const emit = defineEmits(['update:modelValue'])
const model = computed({
    get: () => props.modelValue,
    set: v  => emit('update:modelValue', v)
})
</script>
