<template>
    <AppLayout :title="query ? `Поиск: ${query}` : 'Поиск'">
        <div class="p-4 bg-gray-900 text-white min-h-screen">
            <h1 class="text-2xl font-bold mb-4">Результаты поиска по запросу: "{{ query }}"</h1>

            <div v-if="!query.trim() && recommended.length">
                <h2 class="text-xl font-semibold mb-2">Рекомендуемые</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                    <ArtworkCard
                        v-for="aw in recommended"
                        :key="aw.id"
                        :art="aw"
                        @save="openCollectionSelector"
                        @like="likeArt"
                    />
                </div>
            </div>
            <div v-else>
                <h2 class="text-xl font-semibold mb-2">Работы</h2>
                <div v-if="artworks.length > 0" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                    <ArtworkCard
                        v-for="aw in artworks"
                        :key="aw.id"
                        :art="aw"
                        @save="openCollectionSelector"
                        @like="likeArt"
                    />
                </div>
                <div v-else class="text-gray-400 mb-4">Нет работ</div>

                <h2 class="text-xl font-semibold mb-2 mt-4">Авторы</h2>
                <div v-if="authors.length > 0" class="flex flex-col space-y-2">
                    <div v-for="u in authors" :key="u.id">
                        <Link :href="`/profile/${u.id}`" class="text-blue-400 hover:underline">{{u.name}}</Link>
                    </div>
                </div>
                <div v-else class="text-gray-400 mb-4">Нет авторов</div>

                <h2 class="text-xl font-semibold mb-2 mt-4">По тегам</h2>
                <div v-if="tagResults.length > 0" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                    <ArtworkCard
                        v-for="aw in tagResults"
                        :key="aw.id"
                        :art="aw"
                        @save="openCollectionSelector"
                        @like="likeArt"
                    />
                </div>
                <div v-else class="text-gray-400 mb-4">Нет результатов по тегам</div>

                <div v-if="artworks.length===0 && authors.length===0 && tagResults.length===0 && recommended.length > 0">
                    <h2 class="text-xl font-semibold mb-2">Рекомендуемые</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                        <ArtworkCard
                            v-for="aw in recommended"
                            :key="aw.id"
                            :art="aw"
                            @save="openCollectionSelector"
                            @like="likeArt"
                        />
                    </div>
                </div>
            </div>

            <CollectionSelector
                v-if="showCollectionSelectorFlag"
                :collections="userCollections"
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

            <div v-if="notificationMessage" class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-black bg-opacity-80 text-white px-4 py-2 rounded">
                {{ notificationMessage }}
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'
import AppLayout from "@/Layouts/AppLayout.vue"
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import axios from 'axios'

const page = usePage()
const query = page.props.query || ''
const artworks = page.props.artworks || []
const authors = page.props.authors || []
const tagResults = page.props.tagResults || []
const recommended = page.props.recommended || []
const userCollections = ref(page.props.collections || [])

const showCollectionSelectorFlag = ref(false)
const showCreateCollectionModal = ref(false)
const selectedArt = ref(null)
const dropdownPosition = ref({ top:0, left:0 })
const notificationMessage = ref(null)

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
            // Обновляем in_collections
            function updateCollections(list) {
                const idx = list.findIndex(a => a.id === selectedArt.value.id)
                if (idx >= 0) {
                    list[idx].in_collections = res.data.in_collections
                }
            }
            updateCollections(artworks)
            updateCollections(tagResults)
            updateCollections(recommended)
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
            function updateLike(list) {
                const idx = list.findIndex(a => a.id === art.id)
                if (idx >= 0) {
                    list[idx].likes_count = res.data.likes_count
                    list[idx].liked_by_user = res.data.liked
                }
            }
            updateLike(artworks)
            updateLike(tagResults)
            updateLike(recommended)
            notificationMessage.value = res.data.liked ? 'Лайкнуто' : 'Лайк удален'
            setTimeout(() => notificationMessage.value = null, 3000)
        }).catch(err => console.log(err))
}
</script>
