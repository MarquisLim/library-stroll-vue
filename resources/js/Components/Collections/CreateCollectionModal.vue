<template>
    <div class="modal modal-open">
        <div class="modal-box bg-base-100 dark:bg-base-900 text-base-content dark:text-base-content">
            <h3 class="font-bold text-lg">Создать коллекцию</h3>
            <input
                v-model="name"
                type="text"
                placeholder="Название"
                class="w-full mt-2 px-2 py-1 rounded border border-base-300 dark:border-base-700
               bg-base-200 dark:bg-base-800 placeholder-base-content/50 focus:outline-none"
            />
            <label class="flex items-center space-x-2 mt-2">
                <input type="checkbox" v-model="isPrivate" class="checkbox checkbox-primary" />
                <span>Приватная</span>
            </label>
            <div class="modal-action">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <button
                    class="btn btn-primary"
                    @click="createCollection"
                    :disabled="!name.trim()"
                >Создать</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'
const emit     = defineEmits(['close','created'])
const name     = ref('')
const isPrivate= ref(false)

function createCollection(){
    axios.post('/studio/create-collection', {
        name: name.value,
        is_private: isPrivate.value
    }).then(res => {
        emit('created', res.data.collection)
        emit('close')
    }).catch(console.error)
}
</script>
