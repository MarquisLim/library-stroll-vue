<template>
    <!-- Trigger -->
    <button @click="opened = true"
            class="relative flex items-center justify-center w-12 h-12
           bg-base-200 hover:bg-base-300 text-base-content rounded-full me-3">
        <FunnelIcon class="w-7 h-7" />
        <span v-if="count"
              class="absolute -top-1 -right-1 bg-primary text-primary-content
                 text-[10px] w-5 h-5 rounded-full flex items-center justify-center">
      {{ count }}
    </span>
    </button>

    <!-- Overlay + Panel -->
    <div v-if="opened" class="fixed inset-0 z-50 flex pt-safe-t">
        <!-- backdrop -->
        <div class="flex-1 bg-black/50" @click="opened = false" />

        <!-- panel -->
        <aside class="w-64 bg-base-200 p-4 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold">Фильтры</h3>
                <button @click="opened = false" class="p-1 rounded hover:bg-base-300">
                    <XMarkIcon class="w-6 h-6 text-base-content" />
                </button>
            </div>

            <h3 class="font-semibold mb-2">Категория</h3>
            <div class="space-y-2">
                <Radio v-for="c in categories" :key="c.value"
                       :label="c.label" :value="c.value"
                       v-model="local.category"/>
            </div>

            <h3 class="font-semibold mt-4 mb-2">AI</h3>
            <Radio label="Любые"   value=""   v-model="local.ai"/>
            <Radio label="AI only" value="1"  v-model="local.ai"/>
            <Radio label="Без AI"  value="0"  v-model="local.ai"/>

            <h3 class="font-semibold mt-4 mb-2">Сортировка</h3>
            <div class="space-y-2">
                <Radio v-for="s in sorts" :key="s.value"
                       :label="s.label" :value="s.value"
                       v-model="local.sort"/>
            </div>

            <button class="btn btn-primary w-full mt-6" @click="apply">
                Применить
            </button>
        </aside>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { FunnelIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import Radio from '@/Components/UI/Radio.vue'

const props = defineProps({
    modelValue: Object
})
const emit   = defineEmits(['update:modelValue'])

const opened = ref(false)
const local  = ref({ ...props.modelValue })

watch(() => props.modelValue, v => local.value = { ...v })

function apply(){
    emit('update:modelValue', { ...local.value })
    opened.value = false
}

const count = computed(()=>{
    let c = 0
    if (local.value.category!=='all') c++
    if (local.value.ai!=='')          c++
    if (local.value.sort!=='popular') c++
    return c
})

const categories = [
    { label:'Все',          value:'all'    },
    { label:'Изображения',  value:'images' },
    { label:'Видео',        value:'videos' }
]
const sorts = [
    { label:'Популярные', value:'popular' },
    { label:'Просмотры',  value:'views'   },
    { label:'Последние',   value:'latest'  }
]
</script>
