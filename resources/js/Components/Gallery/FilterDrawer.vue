<template>
    <!-- иконка / счётчик -->
    <button @click="opened = true"
        class="relative flex items-center justify-center w-12 h-12
         bg-gray-700 hover:bg-gray-600 text-white rounded-full">
        <img src="/images/icons/filter.svg" alt="Filter icon" class="w-7 h-7">
        <span v-if="count"
              class="absolute -top-1 -right-1 bg-purple-600 text-[10px]
               w-5 h-5 rounded-full flex items-center justify-center">
            {{ count }}
        </span>
    </button>


    <!-- Panel -->
    <div v-if="opened"
         class="fixed inset-0 z-50 flex">
        <!-- BG Dark -->
        <div class="flex-1 bg-black/50" @click="opened=false"/>
        <aside class="w-64 bg-gray-800 p-4 overflow-y-auto">
            <h3 class="font-semibold mb-3">Категория</h3>
            <div class="space-y-2">
                <Radio v-for="c in categories" :key="c"
                       :label="c.label" :value="c.value"
                       v-model="local.category"/>
            </div>

            <h3 class="font-semibold mt-6 mb-3">AI</h3>
            <Radio label="Любые"  value=""    v-model="local.ai"/>
            <Radio label="AI only" value="1"  v-model="local.ai"/>
            <Radio label="Без AI"  value="0"  v-model="local.ai"/>

            <h3 class="font-semibold mt-6 mb-3">Сортировка</h3>
            <Radio v-for="s in sorts" :key="s.value"
                   :label="s.label" :value="s.value"
                   v-model="local.sort"/>

            <button class="btn btn-primary w-full mt-6"
                    @click="apply">Применить</button>
        </aside>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import Radio from '@/Components/UI/Radio.vue'

const props = defineProps({
    modelValue: Object        // { category:'all', ai:'', sort:'popular' }
})
const emit  = defineEmits(['update:modelValue'])

const opened = ref(false)
const local  = ref({ ...props.modelValue })

watch(()=>props.modelValue, v=> local.value = { ...v })

function apply(){
    emit('update:modelValue', { ...local.value })
    opened.value = false
}

/* Count Active Filter */
const count = computed(()=>{
    const {category, ai, sort} = local.value
    let c = 0
    if(category!=='all') c++
    if(ai!=='')          c++
    if(sort!=='popular') c++
    return c
})

const categories = [
    { label: 'Все',         value: 'all' },
    { label: 'Изображения', value: 'images' },
    { label: 'Видео',       value: 'videos' }
]
const sorts = [
    {label:'Популярные', value:'popular'},
    {label:'Просмотры',  value:'views'},
    {label:'Последние',  value:'latest'}
]
</script>
