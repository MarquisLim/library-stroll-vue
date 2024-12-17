<template>
    <AppLayout :title="collection.name + ' - Коллекция'">
        <div class="min-h-screen text-white p-4 relative">
            <!-- Кнопка назад -->
            <div class="absolute top-4 left-4 z-50">
                <button class="bg-black bg-opacity-50 hover:bg-opacity-80 rounded-full p-2 flex items-center justify-center"
                        @click="goBack">
                    <img src="/images/icons/back.svg" alt="back" class="w-4 h-4" />
                </button>
            </div>

            <!-- Центрирование контента -->
            <div class="flex flex-col items-center text-center pt-10">
                <!-- Блок коллекции в закругленном квадрате -->
                <div class="bg-purple-900 rounded-lg p-4 flex flex-col items-center w-64">
                    <!-- Закругленная картинка-аватар коллекции -->
                    <div class="w-24 h-24 mb-4 rounded-full overflow-hidden bg-purple-600 flex items-center justify-center">
                        <template v-if="artworks.length && artworks[0].media && artworks[0].media.length">
                            <img :src="artworks[0].media[0].original_url" alt="Collection Avatar" class="w-full h-full object-cover" />
                        </template>
                        <template v-else>
                            <!-- Заглушка, если нет медиа -->
                            <img src="/images/icons/collection-placeholder.svg" alt="Placeholder" class="w-full h-full object-cover" />
                        </template>
                    </div>

                    <h1 class="text-2xl font-bold mb-2">{{ collection.name }}</h1>
                    <h2 class="text-lg font-semibold mb-1">{{ author.name }}</h2>
                    <p class="text-gray-200 text-sm mb-1">
                        Работ: {{ collection.artworks_count }}
                    </p>
                    <p class="text-gray-300 text-sm">
                        Создано: {{ new Date(collection.created_at).toLocaleDateString() }}
                    </p>
                </div>
            </div>

            <!-- Список работ в коллекции -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2 mt-10 relative z-10">
                <ArtworkCard
                    v-for="art in artworks"
                    :key="art.id"
                    :art="art"
                    @save="openCollectionSelector"
                    @like="likeArt"
                />
            </div>

            <!-- Модальные окна и всплывающие элементы -->
            <CollectionSelector
                v-if="showCollectionSelectorFlag"
                :collections="userCollections"
                :position="dropdownPosition"
                :selected-collections="selectedArt.in_collections"
                @close="showCollectionSelectorFlag=false"
                @selected="saveArtToCollection"
                @createCollection="createCollection"
                class="z-50"
            />

            <CreateCollectionModal
                v-if="showCreateCollectionModal"
                @close="showCreateCollectionModal=false"
                @created="collectionCreated"
                class="z-50"
            />

            <div v-if="notificationMessage"
                 class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-black bg-opacity-80 text-white px-4 py-2 rounded z-50">
                {{ notificationMessage }}
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import axios from 'axios'

const page = usePage()
const collection = page.props.collection
const author = page.props.author
const artworks = page.props.artworks || []

// Теперь userCollections не пуст, т.к. мы его передали из контроллера
const userCollections = ref(page.props.userCollections || [])

const showCollectionSelectorFlag = ref(false)
const showCreateCollectionModal = ref(false)
const selectedArt = ref(null)
const dropdownPosition = ref({ top:0, left:0 })
const notificationMessage = ref(null)

function goBack() {
    window.history.back()
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
            setTimeout(() => notificationMessage.value = null, 3000)
            const idx = artworks.findIndex(a => a.id === selectedArt.value.id)
            if(idx >= 0) {
                artworks[idx].in_collections = res.data.in_collections
            }
        }).catch(err => console.log(err))
}

function createCollection() {
    showCreateCollectionModal.value = true
}

function collectionCreated(col) {
    userCollections.value.push(col)
    showCreateCollectionModal.value = false
    notificationMessage.value = 'Коллекция создана'
    setTimeout(() => notificationMessage.value = null, 3000)
}

function likeArt(art) {
    axios.post(`/artworks/${art.id}/like`)
        .then(res => {
            const idx = artworks.findIndex(a => a.id === art.id)
            if (idx >= 0) {
                artworks[idx].likes_count = res.data.likes_count
                artworks[idx].liked_by_user = res.data.liked
            }
            notificationMessage.value = res.data.liked ? 'Лайкнуто' : 'Лайк удален'
            setTimeout(() => notificationMessage.value = null, 3000)
        }).catch(err => console.log(err))
}
</script>

<style scoped>
.z-50 {
    z-index: 50;
}
</style>
