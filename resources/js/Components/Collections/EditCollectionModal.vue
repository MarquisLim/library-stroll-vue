<template>
    <div class="modal modal-open">
        <div class="modal-box bg-gray-800 text-white">
            <h3 class="font-bold text-lg">Редактировать коллекцию</h3>
            <input
                v-model="localCollection.name"
                type="text"
                placeholder="Название"
                class="w-full mt-2 px-2 py-1 rounded border border-gray-600 bg-gray-700"
            />
            <label class="flex items-center space-x-2 mt-2">
                <input type="checkbox" v-model="localCollection.is_private" class="checkbox checkbox-primary" />
                <span>Приватная</span>
            </label>

            <div class="modal-action">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <button class="btn btn-primary" @click="saveChanges" :disabled="!localCollection.name.trim()">
                    Сохранить
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive, toRefs, watch } from 'vue'

const props = defineProps({
    initialCollection: {
        type: Object,
        required: true
    }
})
const emit = defineEmits(['close','saved'])

// Делаем локальную копию коллекции
const localCollection = reactive({
    name: props.initialCollection.name || '',
    is_private: !!props.initialCollection.is_private
})

// Если надо отслеживать обновление props
watch(() => props.initialCollection, newVal => {
    localCollection.name = newVal.name
    localCollection.is_private = !!newVal.is_private
}, { deep: true })

function saveChanges() {
    // Сообщаем родителю о новом значении
    emit('saved', {
        name: localCollection.name,
        is_private: localCollection.is_private
    })
}
</script>
