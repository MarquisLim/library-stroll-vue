<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { usePage }                   from '@inertiajs/vue3'
import AppLayout                     from '@/Layouts/AppLayout.vue'
import MasonryGrid                   from '@/Components/MasonryGrid.vue'
import ArtworkCard                   from '@/Components/Gallery/ArtworkCard.vue'
import FilterDrawer                  from '@/Components/Gallery/FilterDrawer.vue'
import TagSlider                     from '@/Components/Gallery/TagSlider.vue'
import { useArtworkActions }         from '@/stores/useArtworkActions'

// 1. Inertia-props
const page = usePage()
const initialArtworks = page.props.artworks

// 2. Локальный массив для Masonry
const items = ref([])

// 3. Популярные тэги
const popularTags = ref(page.props.popularTags || [])

// 4. Фильтры и тэг
const filters = ref({ category:'all', ai:'', sort:'popular' })
const tag     = ref('all')
function selectAllTags() {
    tag.value = 'all'
}

// 5. Собираем query-строку
const query = computed(() => {
    const p = new URLSearchParams()
    if (filters.value.category!=='all') p.set('category', filters.value.category)
    if (filters.value.ai!=='')          p.set('ai',       filters.value.ai)
    if (filters.value.sort!=='popular') p.set('sort',     filters.value.sort)
    if (tag.value!=='all')              p.set('tag',      tag.value)
    return p.toString()
})

// 6. Ключ для принудительного перемонтирования Masonry
const gridKey = computed(() => btoa(query.value))

// 7. Сразу сохраняем коллекции в Pinia
const { setCollections } = useArtworkActions()
if (page.props.collections) {
    setCollections(page.props.collections)
}

// 8. При первой отрисовке вставляем initialArtworks
onMounted(() => {
    items.value = initialArtworks
})

// 9. При смене фильтров/тэга — ОЧИЩАЕМ items,
//    MasonryGrid посмотрит items.length===0 и загрузит page=1
watch([filters, tag], () => {
    items.value = []
})

function appendItems(newItems) {
    const existingIds = new Set(items.value.map(i => i.id))
    items.value.push(
        ...newItems.filter(i => !existingIds.has(i.id))
    )
}
</script>

<template>
    <AppLayout title="Галерея">
        <!-- панель фильтров -->
        <div class="flex items-center mb-4 px-4 py-2">
            <FilterDrawer v-model="filters" />
            <button
                @click="selectAllTags"
                :class="tag==='all'
                  ? 'bg-purple-600 text-white'
                  : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                class="h-12 px-5 rounded-full font-medium whitespace-nowrap me-3"
            >
                Все
            </button>
            <TagSlider class="flex-1" v-model="tag" :tags="popularTags" />
        </div>

        <!-- MasonryGrid -->
        <MasonryGrid
            :key="gridKey"
            :items="items"
            :start-page="items.length > 0 ? 1 : 0"
            :load-more-url="`/gallery/load-more?${query}`"
            @update:items="appendItems"
        >
            <template #default="{ item }">
                <ArtworkCard :art="item" />
            </template>
        </MasonryGrid>
    </AppLayout>
</template>
