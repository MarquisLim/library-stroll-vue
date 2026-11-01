<script setup>
import UserFormModal from './UserFormModal.vue'
defineProps({ roles: { type: Array, default: () => [] } })
const emit = defineEmits(['close', 'created'])
</script>
<template><UserFormModal :roles="roles" @close="emit('close')" @saved="user => emit('created', user)" /></template>
