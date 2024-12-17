<template>
    <AppLayout :title="`${profileUser.name} - Профиль`">
        <div class="p-4 bg-gray-900 text-white min-h-screen">
            <!-- Информация о пользователе -->
            <div class="flex flex-col items-center text-center mb-4">
                <img :src="profileUser.profile_photo_url" alt="Avatar" class="w-40 h-40 rounded-full object-cover mb-2">
                <h1 class="text-2xl font-bold mb-1">{{ profileUser.name }}</h1>
                <p class="text-gray-400 text-sm mb-1">
                    Присоединился: {{ new Date(profileUser.created_at).toLocaleDateString() }}
                </p>
                <div v-if="isOwner">
                    <Link :href="route('profile.show')" class="text-blue-400 hover:underline">Настройки профиля</Link>
                </div>
            </div>

            <!-- Коллекции (слайдер) -->
            <div class="mb-4">
                <h2 class="text-xl font-semibold mb-2">Коллекции</h2>
                <div class="overflow-x-auto flex space-x-4 pb-2">
                    <CollectionCard
                        v-for="collection in collections"
                        :key="collection.id"
                        :collection="collection"
                    />
                </div>
            </div>

            <!-- Вкладки: Созданные | Лайкнутые -->
            <div class="mb-4 flex justify-center space-x-4">
                <button
                    @click="currentTab='created'"
                    :class="currentTab==='created' ? 'border-b-2 border-blue-500 text-blue-400' : 'text-gray-400'"
                    class="pb-2"
                >
                    Созданные
                </button>
                <button
                    @click="currentTab='liked'; loadLikedArtworks()"
                    :class="currentTab==='liked' ? 'border-b-2 border-blue-500 text-blue-400' : 'text-gray-400'"
                    class="pb-2"
                >
                    Лайкнутые
                </button>
            </div>

            <!-- Содержимое вкладок -->
            <div v-if="currentTab==='created'">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                    <ArtworkCard
                        v-for="art in artworks"
                        :key="art.id"
                        :art="art"
                        @save="openCollectionSelector"
                        @like="likeArt"
                    />
                </div>
            </div>
            <div v-else-if="currentTab==='liked'">
                <div v-if="loadingLikes" class="text-center py-4">Загрузка лайкнутых работ...</div>
                <div v-else class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                    <ArtworkCard
                        v-for="art in likedArtworks"
                        :key="art.id"
                        :art="art"
                        @save="openCollectionSelector"
                        @like="likeArt"
                    />
                </div>
            </div>

            <!-- Компоненты для добавления в коллекцию, уведомления и т.п. -->
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
import CollectionCard from '@/Components/Collections/CollectionCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import axios from 'axios'

const page = usePage()
const profileUser = page.props.profileUser
const artworks = page.props.artworks || []
const collections = page.props.collections || []
const isOwner = page.props.isOwner || false

// Если хотите всегда показывать коллекции текущего залогиненного пользователя в селекторе:
const userCollections = ref(page.props.userCollections || [])

const currentTab = ref('created')
const likedArtworks = ref([])
const loadingLikes = ref(false)

function loadLikedArtworks() {
    if(likedArtworks.value.length === 0){
        loadingLikes.value = true
        axios.get(`/profile/${profileUser.id}/likes`)
            .then(res => {
                likedArtworks.value = res.data
                loadingLikes.value = false
            })
            .catch(err => {
                console.log(err)
                loadingLikes.value = false
            })
    }
}

const showCollectionSelectorFlag = ref(false)
const showCreateCollectionModal = ref(false)
const selectedArt = ref(null)
const dropdownPosition = ref({ top:0,left:0 })
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
            const idx = artworks.findIndex(a => a.id === selectedArt.value.id)
            if (idx >= 0) {
                artworks[idx].in_collections = res.data.in_collections
            }
            const idxLiked = likedArtworks.value.findIndex(a => a.id === selectedArt.value.id)
            if(idxLiked >= 0) {
                likedArtworks.value[idxLiked].in_collections = res.data.in_collections
            }
        }).catch(err => console.log(err))
}

function createCollection() {
    showCreateCollectionModal.value = true
}

function collectionCreated(col) {
    // col уже содержит artworks=[]
    userCollections.value.push(col)
    showCreateCollectionModal.value = false
    notificationMessage.value = 'Коллекция создана'
    setTimeout(() => notificationMessage.value = null, 3000)
}

function likeArt(art) {
    axios.post(`/artworks/${art.id}/like`)
        .then(res => {
            const updateArtwork = (list) => {
                const index = list.findIndex(a => a.id === art.id)
                if (index >= 0) {
                    list[index].likes_count = res.data.likes_count
                    list[index].liked_by_user = res.data.liked
                }
            }
            updateArtwork(artworks)
            updateArtwork(likedArtworks.value)
            notificationMessage.value = res.data.liked ? 'Лайкнуто' : 'Лайк удален'
            setTimeout(() => notificationMessage.value = null, 3000)
        }).catch(err => console.log(err))
}
</script>


<style scoped>
/* Стили можно дополнять */
.overflow-x-auto {
    overflow-x: auto;
}
</style>
