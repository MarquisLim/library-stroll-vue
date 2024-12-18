<template>
    <div class="absolute z-50 p-4 rounded shadow"
         :class="'bg-gray-800 text-white w-64'"
         :style="{ position: 'absolute', top: position.top - 100 + 'px', left: position.left + 'px' }">
        <input type="text" placeholder="Поиск коллекций..." class="w-full px-2 py-1 rounded border border-gray-600 bg-gray-700 mb-2" @input="filterCollections"/>
        <div class="max-h-40 overflow-auto mb-2 bg-gray-700 rounded p-1">
            <div v-for="col in filteredCollections" :key="col.id"
                 class="flex items-center space-x-2 hover:bg-gray-600 px-2 py-1 cursor-pointer rounded">
                <input type="checkbox" :value="col.id" v-model="selectedCols" :checked="selectedCols.includes(col.id)" class="checkbox checkbox-primary" />
                <span class="flex-1 text-white font-medium">{{ col.name }}</span>
            </div>
        </div>
        <button class="btn btn-primary w-full mb-2" @click="save">Сохранить</button>
        <button class="btn w-full" @click="$emit('createCollection')">Создать коллекцию</button>
        <button class="btn w-full mt-2 bg-gray-700 hover:bg-gray-600" @click="$emit('close')">Отмена</button>
    </div>
</template>

<script setup>
import { ref, computed, defineEmits, defineProps, watch } from 'vue'

const props = defineProps({
    collections: Array,
    position: Object,
    selectedCollections: Array
})
const emit = defineEmits(['close', 'selected', 'createCollection'])

const filter = ref('')
// Инициализируем selectedCols с текущими коллекциями
const selectedCols = ref(props.selectedCollections ? [...props.selectedCollections] : [])

watch(() => props.selectedCollections, (newVal) => {
    selectedCols.value = newVal ? [...newVal] : []
})

const filteredCollections = computed(() => {
    return props.collections.filter(c => c.name.toLowerCase().includes(filter.value.toLowerCase()))
})

function filterCollections(e) {
    filter.value = e.target.value
}

function save() {
    emit('selected', selectedCols.value)
}
</script>

<style scoped>
/* Добавьте любые дополнительные стили при необходимости */
.checkbox {
    margin-right: 0.5rem;
    transform: scale(1.2);
}

.text-white {
    color: #ffffff;
}

.bg-gray-600:hover {
    background-color: #4b5563;
}

.font-medium {
    font-weight: 500;
}

.rounded {
    border-radius: 0.375rem;
}
</style>
