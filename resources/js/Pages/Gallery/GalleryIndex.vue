<template>
    <AppLayout title="Галерея">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                Галерея
            </h2>
        </template>

        <div class="p-4 bg-gray-900 text-white min-h-screen overflow-auto" @scroll.passive="handleScroll">
            <div class="columns-1 sm:columns-2 md:columns-3 lg:columns-4 gap-2">
                <ArtworkCard
                    v-for="art in artworks"
                    :key="art.id"
                    :art="art"
                    @save="openCollectionSelector(art)"
                    @like="likeArt(art)"
                />
            </div>
            <div v-if="loading" class="text-center py-4">Загрузка...</div>

            <!-- Проверяем, есть ли collections -->
            <CollectionSelector
                v-if="showCollectionSelectorFlag && collections.length"
                :collections="collections"
                @close="showCollectionSelectorFlag=false"
                @selected="saveArtToCollection"
                @createCollection="createCollection"
            />

            <CreateCollectionModal
                v-if="showCreateCollectionModal"
                @close="showCreateCollectionModal=false"
                @created="collectionCreated"
            />
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import AppLayout from "@/Layouts/AppLayout.vue";

const page = usePage()
const artworks = ref(Array.isArray(page.props.artworks) ? [...page.props.artworks] : [])
const collections = ref(Array.isArray(page.props.collections) ? [...page.props.collections] : [])

const loading = ref(false)
const pageNumber = ref(1)

const showCollectionSelectorFlag = ref(false)
const showCreateCollectionModal = ref(false)
const selectedArt = ref(null)

function handleScroll(e) {
    const el = e.target
    const bottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 100
    if (bottom && !loading.value) {
        loadMore()
    }
}

function loadMore() {
    loading.value = true
    pageNumber.value++
    axios.get(`/gallery/load-more?page=${pageNumber.value}`)
        .then(res => {
            artworks.value.push(...res.data.artworks)
            loading.value = false
        }).catch(err => {
        loading.value = false;
        console.log(err)
    })
}

function openCollectionSelector(art) {
    if (!collections.value.length) {
        // Если нет коллекций, возможно сразу предложить создать?
        showCreateCollectionModal.value = true
        selectedArt.value = art
        return
    }
    selectedArt.value = art
    showCollectionSelectorFlag.value = true
}

function saveArtToCollection(colId) {
    // Сохранить art в коллекцию
    axios.post(`/artworks/${selectedArt.value.id}/add-to-collection`, { collections: [colId] })
        .then(res => {
            showCollectionSelectorFlag.value = false
        }).catch(err => console.log(err))
}

function createCollection() {
    showCreateCollectionModal.value = true
}

function collectionCreated(col) {
    collections.value.push(col)
    showCreateCollectionModal.value = false
}

function likeArt(art) {
    axios.post(`/artworks/${art.id}/like`)
        .then(res => {
            const idx = artworks.value.findIndex(a => a.id === art.id)
            if (idx >= 0) {
                artworks.value[idx].likes_count = res.data.likes_count
            }
        }).catch(err => console.log(err))
}
</script>
