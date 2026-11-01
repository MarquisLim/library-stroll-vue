<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button :type="type" class="btn border-base-300 bg-base-100 text-base-content hover:bg-base-200">
        <slot />
    </button>
</template>
