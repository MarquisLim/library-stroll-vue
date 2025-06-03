<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { usePage }                   from '@inertiajs/vue3'
import AppLayout                     from '@/Layouts/AppLayout.vue'
import MasonryGrid                   from '@/Components/MasonryGrid.vue'
import ArtworkCard                   from '@/Components/Gallery/ArtworkCard.vue'
import FilterDrawer                  from '@/Components/Gallery/FilterDrawer.vue'
import TagSlider                     from '@/Components/Gallery/TagSlider.vue'
import { useArtworkActions }         from '@/stores/useArtworkActions'

const page = usePage()
const initialArtworks = page.props.artworks

const items = ref([])

const popularTags = ref(page.props.popularTags || [])

const filters = ref({ category:'all', ai:'', sort:'popular' })
const tag     = ref('all')
function selectAllTags() {
    tag.value = 'all'
}

const query = computed(() => {
    const p = new URLSearchParams()
    if (filters.value.category!=='all') p.set('category', filters.value.category)
    if (filters.value.ai!=='')          p.set('ai',       filters.value.ai)
    if (filters.value.sort!=='popular') p.set('sort',     filters.value.sort)
    if (tag.value!=='all')              p.set('tag',      tag.value)
    return p.toString()
})

const gridKey = computed(() => btoa(query.value))

const visibleTags = computed(() => {
    const usedIds = new Set(
        items.value.flatMap(a => (a.tags ?? []).map(t => t.id))
    )
    return popularTags.value.filter(t => usedIds.has(t.id))
})

const { setCollections } = useArtworkActions()
if (page.props.collections) {
    setCollections(page.props.collections)
}

onMounted(() => {
    items.value = initialArtworks
})


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
            <TagSlider class="flex-1" v-model="tag" :tags="visibleTags" />
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
