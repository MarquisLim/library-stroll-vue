<template>
    <AppLayout :title="query ? `Поиск – ${query}` : 'Поиск'">
        <div class="p-4 bg-gray-900 text-white min-h-screen">

            <!-- ─── Заголовок + поля ввода (если нужно) -->
            <h1 class="text-2xl font-bold mb-6">
                {{ query ? `“${query}”` : 'Поиск' }}
            </h1>

            <!-- ─── Вкладки -->
            <div class="flex space-x-6 mb-6">
                <button
                    v-for="t in tabs"
                    :key="t"
                    :class="current===t
                      ? 'border-b-2 border-blue-500 text-blue-400 pb-1'
                      : 'text-gray-400 pb-1'"
                    @click="current=t"
                >
                    {{ t==='artworks' ? 'Работы'
                    : t==='tags' ? 'Теги'
                        : 'Авторы' }}
                    <span v-if="counts[t]" class="ml-1 text-xs text-gray-500">
                      ({{ counts[t] }})
                    </span>
                </button>
            </div>

            <!-- ─── Работы ─── -->
            <MasonryGrid
                v-if="current==='artworks'"
                :items="artworksRaw"
                class="mb-8"
            >
                <template #default="{ item }">
                    <ArtworkCard :art="item" />
                </template>
            </MasonryGrid>

            <!-- ─── Теги  ─── -->
            <MasonryGrid v-if="current==='tags' && tagArtworks.length"
                         :items="tagArtworks" class="mb-8">
                <template #default="{ item }"><ArtworkCard :art="item" /></template>
            </MasonryGrid>
            <p v-else-if="current==='tags'" class="text-gray-500 mb-8">
                Нет работ по тегам
            </p>


            <!-- ─── Авторы ─── -->
            <div v-if="current==='authors'"
                 class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <AuthorCard v-for="a in authors" :key="a.id" :author="a" />
            </div>

            <!-- ─── При полном отсутствии результатов ─── -->
            <template v-if="!artworksRaw.length && !authors.length && !tags.length">
                <h2 class="text-xl font-semibold mb-2 mt-8">Рекомендуем</h2>
                <MasonryGrid :items="recommended">
                    <template #default="{ item }">
                        <ArtworkCard :art="item" />
                    </template>
                </MasonryGrid>
            </template>

        </div>
    </AppLayout>
</template>
<script setup>
import { ref, computed, watchEffect, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useArtworkActions } from '@/stores/useArtworkActions'
import AppLayout   from '@/Layouts/AppLayout.vue'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import MasonryGrid from '@/Components/MasonryGrid.vue'
import AuthorCard  from '@/Components/User/AuthorCard.vue'

/* ---------- данные ---------- */
const { props }   = usePage()
const query       = props.q           ?? ''
const artworksRaw = props.artworks    ?? []
const authors     = props.authors     ?? []
const tags        = props.tags        ?? []
const recommended = props.recommended ?? []
const collections = props.collections ?? []

/* ---------- Pinia ---------- */
onMounted(()=> useArtworkActions().setCollections(collections))

/* ---------- удобная «плоская» коллекция работ по тегам ---------- */
const tagArtworks = computed(()=> tags
    .flatMap(t => t.artworks ?? []))

/* ---------- вкладки ---------- */
const tabs    = ['artworks','tags','authors']
const current = ref('artworks')

watchEffect(()=>{
    if (!artworksRaw.length && tagArtworks.value.length)
        current.value = 'tags'
    if (!artworksRaw.length && !tagArtworks.value.length && authors.length)
        current.value = 'authors'
})

/* ---------- счётчики ---------- */
const counts = computed(()=>({
    artworks: artworksRaw.length,
    tags:     tagArtworks.value.length,   // ← суммарно, а не число тегов
    authors:  authors.length
}))
</script>
