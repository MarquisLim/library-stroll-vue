<script setup>
import { ref } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
const emit = defineEmits(['close', 'created'])
const name = ref('')
const isPrivate = ref(false)
const processing = ref(false)
const error = ref('')
async function createCollection() {
    if (!name.value.trim() || processing.value) return
    processing.value = true
    error.value = ''
    try { const response = await axios.post('/collections/create', { name: name.value.trim(), is_private: isPrivate.value }); emit('created', response.data.collection); emit('close') }
    catch (failure) { error.value = failure.response?.data?.errors?.name?.[0] || 'Не удалось создать коллекцию. Попробуйте ещё раз.' }
    finally { processing.value = false }
}
</script>
<template>
    <Modal :show="true" max-width="md" :closeable="!processing" @close="emit('close')">
        <form class="p-6 sm:p-8" @submit.prevent="createCollection">
            <p class="ui-eyebrow">Ваша студия</p><h2 class="mt-3 text-2xl">Новая коллекция</h2><p class="ui-muted mt-3 text-sm">Место для работ, объединённых вашей идеей.</p>
            <label class="mt-6 block text-sm font-semibold">Название<input v-model="name" required maxlength="255" class="input mt-2 w-full" placeholder="Например, «Цвет и настроение»" :disabled="processing" /></label>
            <label class="studio-setting mt-5 cursor-pointer"><span><span class="block font-semibold">Приватная коллекция</span><span class="ui-muted block text-xs">Будет доступна только вам</span></span><input type="checkbox" v-model="isPrivate" class="toggle toggle-primary toggle-sm" :disabled="processing" /></label>
            <p v-if="error" role="alert" class="mt-4 rounded-xl bg-error/10 p-3 text-sm text-error">{{ error }}</p>
            <div class="mt-6 flex justify-end gap-3 border-t border-base-300 pt-5"><button type="button" class="btn btn-ghost" :disabled="processing" @click="emit('close')">Отмена</button><button class="btn btn-primary" :disabled="!name.trim() || processing"><span v-if="processing" class="loading loading-spinner loading-xs" /> Создать коллекцию</button></div>
        </form>
    </Modal>
</template>
