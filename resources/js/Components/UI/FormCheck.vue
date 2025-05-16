<!-- resources/js/Components/UI/FormCheck.vue -->
<template>
    <label class="flex items-center space-x-2 cursor-pointer">
        <input type="checkbox" class="checkbox checkbox-primary"
               :checked="modelValue"
               :disabled="disabled"
               @change="$emit('change', $event.target.checked)"/>
        <span>{{ label }}</span>
    </label>
</template>
<script setup>
defineProps({ label:String, modelValue:Boolean, disabled:Boolean })
defineEmits(['change'])
</script>
