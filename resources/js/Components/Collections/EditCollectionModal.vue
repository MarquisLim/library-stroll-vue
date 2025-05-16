<template>
    <div class="modal modal-open">
        <div class="modal-box bg-base-100 dark:bg-base-900 text-base-content dark:text-base-content">
            <h3 class="font-bold text-lg">Редактировать коллекцию</h3>
            <input
                v-model="local.name"
                type="text"
                placeholder="Название"
                class="w-full mt-2 px-2 py-1 rounded border border-base-300 dark:border-base-700
               bg-base-200 dark:bg-base-800 placeholder-base-content/50 focus:outline-none"
            />
            <label class="flex items-center space-x-2 mt-2">
                <input
                    type="checkbox"
                    v-model="local.is_private"
                    class="checkbox checkbox-primary"
                />
                <span>Приватная</span>
            </label>
            <div class="modal-action">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <button
                    class="btn btn-primary"
                    @click="saveChanges"
                    :disabled="!local.name.trim()"
                >Сохранить</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive, toRefs, watch } from 'vue'
const props = defineProps({
    initialCollection: { type: Object, required: true }
})
const emit = defineEmits(['close','saved'])

const local = reactive({
    name:       props.initialCollection.name || '',
    is_private: !!props.initialCollection.is_private
})

watch(() => props.initialCollection, val => {
    local.name       = val.name
    local.is_private = !!val.is_private
})

function saveChanges(){
    emit('saved', { ...local })
    emit('close')
}
</script>
