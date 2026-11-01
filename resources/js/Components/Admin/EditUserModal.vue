<script setup>
import UserFormModal from './UserFormModal.vue'
defineProps({ user: Object, roles: Array })
const emit = defineEmits(['close', 'saved'])
</script>
<template><UserFormModal :user="user" :roles="roles" @close="emit('close')" @saved="emit('saved')" /></template>
