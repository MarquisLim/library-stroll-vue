<template>
    <AppLayout title="Галерея">
        <!-- Контейнер со скроллом -->
        <div
            ref="scrollContainer"
            class="p-4 bg-gray-900 text-white min-h-screen overflow-auto relative"
        >
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                <ArtworkCard
                    v-for="art in artworks"
                    :key="art.id"
                    :art="art"
                    @save="openCollectionSelector"
                    @like="likeArt"
                />
            </div>
            <div v-if="loading" class="text-center py-4">Загрузка...</div>
            <div ref="sentinel"></div>

            <CollectionSelector
                v-if="showCollectionSelectorFlag"
                :collections="collections"
                :position="dropdownPosition"
                :selected-collections="selectedArt.in_collections"
                @close="showCollectionSelectorFlag=false"
                @selected="saveArtToCollection"
                @createCollection="createCollection"
            />

            <CreateCollectionModal
                v-if="showCreateCollectionModal"
                @close="showCreateCollectionModal=false"
                @created="collectionCreated"
            />

            <!-- Уведомления -->
            <div v-if="notificationMessage" class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-black bg-opacity-80 text-white px-4 py-2 rounded">
                {{notificationMessage}}
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import AppLayout from "@/Layouts/AppLayout.vue"
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'

const page = usePage()
const artworks = ref(Array.isArray(page.props.artworks) ? [...page.props.artworks] : [])
const collections = ref(Array.isArray(page.props.collections) ? [...page.props.collections] : [])

const loading = ref(false)
const pageNumber = ref(1)

const showCollectionSelectorFlag = ref(false)
const showCreateCollectionModal = ref(false)
const selectedArt = ref(null)
const dropdownPosition = ref({ top: 0, left: 0 })
const notificationMessage = ref(null)

const scrollContainer = ref(null)
const sentinel = ref(null)

let observer = null

function handleIntersect(entries) {
    if(entries[0].isIntersecting && !loading.value){
        console.log('Sentinel intersected, loading more artworks...')
        loadMore()
    }
}

function loadMore() {
    loading.value = true
    console.log(`Loading page ${pageNumber.value + 1}`)
    axios.get(`/gallery/load-more?page=${pageNumber.value + 1}`)
        .then(res => {
            if(res.data.artworks && res.data.artworks.length > 0){
                // Добавляем новые арты в конец массива для сохранения порядка
                artworks.value = [...artworks.value, ...res.data.artworks]
                pageNumber.value++
                console.log(`Loaded page ${pageNumber.value}, total artworks: ${artworks.value.length}`)
            } else {
                console.log('No more artworks to load.')
            }
            loading.value = false
        }).catch(err => {
        loading.value = false
        console.log('Error loading more artworks:', err)
    })
}

function openCollectionSelector({ art, event }) {
    selectedArt.value = art
    const rect = event.currentTarget.getBoundingClientRect()
    dropdownPosition.value = { top: rect.bottom + window.scrollY, left: rect.left + window.scrollX }
    showCollectionSelectorFlag.value = true
}

function saveArtToCollection(colIds) {
    axios.post(`/artworks/${selectedArt.value.id}/add-to-collection`, { collections: colIds })
        .then(res => {
            showCollectionSelectorFlag.value = false
            notificationMessage.value = 'Добавлено в коллекцию'
            setTimeout(() => {
                notificationMessage.value = null
            }, 3000)
            // Обновить in_collections у selectedArt в artworks
            const idx = artworks.value.findIndex(a => a.id === selectedArt.value.id)
            if (idx >= 0) {
                artworks.value[idx].in_collections = res.data.in_collections
                console.log(`Art ID ${selectedArt.value.id} collections updated to`, res.data.in_collections)
            }
        }).catch(err => console.log(err))
}

function createCollection() {
    showCreateCollectionModal.value = true
}

function collectionCreated(col) {
    collections.value.push(col)
    showCreateCollectionModal.value = false
    notificationMessage.value = 'Коллекция создана'
    setTimeout(() => notificationMessage.value = null, 3000)
}

function likeArt(art) {
    axios.post(`/artworks/${art.id}/like`)
        .then(res => {
            const idx = artworks.value.findIndex(a => a.id === art.id)
            if (idx >= 0) {
                artworks.value[idx].likes_count = res.data.likes_count
                artworks.value[idx].liked_by_user = res.data.liked
                notificationMessage.value = res.data.liked ? 'Лайкнуто' : 'Лайк удален'
                console.log(`Art ID ${art.id} liked status updated to ${res.data.liked}`)
                setTimeout(() => {
                    notificationMessage.value = null
                }, 3000)
            }
        }).catch(err => {
        console.log('Error liking art:', err)
    })
}

onMounted(() => {
    observer = new IntersectionObserver(handleIntersect, { threshold: 1.0 })
    if (sentinel.value) {
        observer.observe(sentinel.value)
    }
})

onBeforeUnmount(() => {
    if (observer && sentinel.value) {
        observer.unobserve(sentinel.value)
    }
})
</script>

<style scoped>
/* Используем CSS Grid вместо CSS Columns для стабильного порядка и предотвращения изменений позиций */
.grid {
    display: grid;
}

/* Плавное появление артов */
.grid > * {
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.5s ease, transform 0.5s ease;
}

.grid > *.appear {
    opacity: 1;
    transform: translateY(0);
}
</style>
