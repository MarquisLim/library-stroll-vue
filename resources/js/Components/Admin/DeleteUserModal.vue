<script setup>
import Modal from '@/Components/Modal.vue'
defineProps({ user: Object, processing: Boolean, error: String })
const emit = defineEmits(['close', 'confirmed'])
</script>
<template>
    <Modal :show="true" max-width="md" :closeable="!processing" @close="emit('close')">
        <div class="p-6 sm:p-8">
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-error/10 text-2xl text-error"><i class="mdi mdi-account-remove-outline" /></span>
            <h2 class="mt-5 text-2xl">Удалить пользователя?</h2>
            <div class="mt-5 rounded-xl border border-base-300 bg-base-200/50 p-4"><p class="font-semibold">{{ user.name }}</p><p class="ui-muted mt-1 break-all text-sm">{{ user.email }}</p></div>
            <p class="ui-muted mt-4 text-sm">Это действие нельзя отменить.</p>
            <p v-if="error" role="alert" class="mt-4 rounded-xl bg-error/10 p-3 text-sm text-error">{{ error }}</p>
            <div class="mt-6 flex flex-wrap justify-end gap-3 border-t border-base-300 pt-5"><button class="btn btn-ghost" :disabled="processing" @click="emit('close')">Отмена</button><button class="btn btn-error" :disabled="processing" @click="emit('confirmed')"><span v-if="processing" class="loading loading-spinner loading-xs" /> Удалить пользователя</button></div>
        </div>
    </Modal>
</template>
