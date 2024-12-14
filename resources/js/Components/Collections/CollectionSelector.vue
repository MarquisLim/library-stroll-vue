<template>
    <div class="absolute bg-white text-black p-4 rounded shadow z-50 top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-64">
        <input type="text" placeholder="Поиск коллекций..." class="w-full px-2 py-1 rounded border mb-2" @input="filterCollections"/>
        <div class="max-h-40 overflow-auto mb-2">
            <div v-for="col in filteredCollections" :key="col.id" class="px-2 py-1 hover:bg-gray-200 cursor-pointer" @click="select(col)">
                {{col.name}}
            </div>
        </div>
        <button class="btn w-full mb-2" @click="$emit('createCollection')">Создать коллекцию</button>
        <button class="btn" @click="$emit('close')">Отмена</button>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
const props = defineProps({ collections:Array })
const filter = ref('')
const filteredCollections = computed(()=>{
    return props.collections.filter(c=>c.name.toLowerCase().includes(filter.value.toLowerCase()))
})

function filterCollections(e){
    filter.value=e.target.value
}

function select(col){
    emit('selected',col.id)
}
</script>
