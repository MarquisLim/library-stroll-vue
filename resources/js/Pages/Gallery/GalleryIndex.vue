<script setup>
import { ref, computed, watch } from 'vue'
import { usePage }               from '@inertiajs/vue3'
import AppLayout                 from '@/Layouts/AppLayout.vue'
import MasonryGrid               from '@/Components/MasonryGrid.vue'
import ArtworkCard               from '@/Components/Gallery/ArtworkCard.vue'
import FilterDrawer              from '@/Components/Gallery/FilterDrawer.vue'
import TagSlider                 from '@/Components/Gallery/TagSlider.vue'
import { useArtworkActions }     from '@/stores/useArtworkActions'

/* ------- данные от Inertia ------- */
const page        = usePage()
const artworks    = ref([...page.props.artworks])
const popularTags = ref(page.props.popularTags || [])

/* ------- фильтры / тег ------- */
const filters = ref({ category:'all', ai:'', sort:'popular' })
const tag     = ref('all')
function selectAllTags(){ tag.value = 'all' }

/* query без page */
const query = computed(()=>{
    const p = new URLSearchParams()
    if(filters.value.category!=='all') p.set('category', filters.value.category)
    if(filters.value.ai!=='')          p.set('ai',       filters.value.ai)
    if(filters.value.sort!=='popular') p.set('sort',     filters.value.sort)
    if(tag.value!=='all')              p.set('tag',      tag.value)
    return p.toString()
})

/* NEW key → MasonryGrid монтируется заново, page=1, loader сброшен */
const gridKey = computed(()=> btoa(query.value))

/* сбрасываем массив, чтобы Masonry видел «0 элементов» и показал loader */
watch([filters, tag], ()=> artworks.value = [])

const { setCollections } = useArtworkActions()
if (page.props.collections) setCollections(page.props.collections)
</script>

<template>
    <AppLayout title="Галерея">
        <!-- ───────── панель фильтров ───────── -->
        <div class="flex items-center space-x-4 mb-4 px-4 py-2">
            <FilterDrawer v-model="filters"/>
            <button @click="selectAllTags"
                    :class="tag === 'all'
                      ? 'bg-purple-600 text-white'
                      : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                    class="h-12 px-5 rounded-full text-base font-medium whitespace-nowrap">
                Все
            </button>


            <TagSlider class="flex-1" v-model="tag" :tags="popularTags"/>
        </div>

        <!-- ───────── MasonryGrid ───────── -->
        <MasonryGrid
            :key="gridKey"
            :items="artworks"
            :loadMoreUrl="`/gallery/load-more?${query}`"
            @update:items="artworks = $event"
        >
        <template #default="{ item }">
            <ArtworkCard :art="item"/>
        </template>
        </MasonryGrid>
    </AppLayout>
</template>
