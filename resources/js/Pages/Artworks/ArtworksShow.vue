<template>
    <AppLayout :title="artwork.title || 'Artwork'">
        <div class="max-w-7xl mx-auto p-4 bg-gray-900 text-white min-h-screen">
            <!-- Заголовок и кнопка "Назад" -->
            <div class="flex items-center space-x-2 mb-4">
                <button class="btn bg-black bg-opacity-50 hover:bg-opacity-80 rounded-full" @click="goBack">
                    <img src="/images/icons/back.svg" alt="back" class="w-4 h-4" />
                </button>
                <h1 class="text-2xl font-bold">{{ artwork.title || 'Без названия' }}</h1>
            </div>

            <!-- Основной контент: изображение/видео и детали -->
            <div class="flex flex-col md:flex-row gap-4">
                <div class="md:w-1/2">
                    <template v-if="artwork.type === 'video'">
                        <video :src="artwork.media[0]?.original_url" class="w-full h-auto object-cover rounded" controls preload="metadata" @loadedmetadata="setVideoDuration" />
                        <span class="text-sm text-gray-400">{{ videoDuration }}</span>
                    </template>
                    <template v-else>
                        <img :src="artwork.media[0]?.original_url" class="w-full h-auto object-cover rounded" loading="lazy" alt="Artwork Image" />
                    </template>
                </div>
                <div class="md:w-1/2 flex flex-col space-y-4">
                    <!-- Описание артворка -->
                    <p>{{ artwork.description }}</p>

                    <!-- Информация об авторе -->
                    <div class="flex items-center space-x-2">
                        <img class="h-8 w-8 rounded-full object-cover" :src="author.profile_photo_url" alt="Author" />
                        <span class="font-semibold">{{ author.name }}</span>
                    </div>

                    <!-- Кнопки лайка, комментариев, просмотров и добавления в коллекции -->
                    <div class="flex items-center space-x-4">
                        <!-- Лайк -->
                        <div class="flex items-center space-x-1">
                            <button @click="likeArtwork" class="rounded-full w-8 h-8 flex items-center justify-center bg-black bg-opacity-50 hover:bg-opacity-80 text-white transition-all duration-300">
                                <img :src="artwork.liked_by_user ? '/images/icons/liked.svg' : '/images/icons/like.svg'" alt="like" class="w-5 h-5" />
                            </button>
                            <span>{{ artwork.likes_count }}</span>
                        </div>

                        <!-- Комментарии -->
                        <div class="flex items-center space-x-1">
                            <img src="/images/icons/comments.svg" alt="comments" class="w-5 h-5" />
                            <span>{{ artwork.comments_count || 0 }}</span>
                        </div>

                        <!-- Просмотры -->
                        <div class="flex items-center space-x-1">
                            <img src="/images/icons/views.svg" alt="views" class="w-5 h-5" />
                            <span>{{ artwork.views_count }}</span>
                        </div>

                        <!-- Добавление в коллекции -->
                        <button class="rounded-full bg-black bg-opacity-50 text-white w-8 h-8 flex items-center justify-center hover:bg-opacity-80 transition-all duration-300" @click="openCollectionSelector">
                            <img src="/images/icons/plus-btn.svg" alt="plus" class="w-5 h-5" />
                        </button>
                    </div>

                    <!-- Комментарии -->
                    <CommentsSection :artworkId="artwork.id" @updateCommentsCount="updateCommentsCount" />
                </div>
            </div>

            <!-- Другие работы автора с Load More -->
            <h2 class="text-xl font-bold mt-8 mb-2">Другие работы автора</h2>
            <div class="bg-gray-800 p-4 rounded">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                    <ArtworkCard
                        v-for="aw in authorWorks"
                        :key="aw.id"
                        :art="aw"
                        @save="openCollectionSelectorFromList(aw)"
                        @like="likeArtFromList"
                    />
                </div>

                <!-- Кнопка "Загрузить еще" -->
                <div v-if="hasMoreAuthorWorks" class="mt-4 flex justify-center">
                    <button class="btn btn-secondary" @click="loadMoreAuthorWorks" :disabled="loadingAuthorWorks">
                        <span v-if="!loadingAuthorWorks">Загрузить еще</span>
                        <span v-else>Загрузка...</span>
                    </button>
                </div>

                <!-- Сообщение о конце списка -->
                <div v-if="!hasMoreAuthorWorks && !loadingAuthorWorks && authorWorks.length > 0" class="mt-4 text-center text-gray-400">
                    Больше работ нет
                </div>

                <!-- Сообщение, если нет работ -->
                <div v-if="!loadingAuthorWorks && authorWorks.length === 0" class="mt-4 text-center text-gray-400">
                    Нет других работ автора
                </div>
            </div>

            <!-- Модальные окна для коллекций -->
            <CollectionSelector
                v-if="showCollectionSelectorFlag"
                :collections="collections"
                :position="dropdownPosition"
                :selected-collections="selectedArt.in_collections"
                @close="showCollectionSelectorFlag = false"
                @selected="saveArtToCollection"
                @createCollection="showCreateCollectionModal = true"
            />

            <CreateCollectionModal
                v-if="showCreateCollectionModal"
                @close="showCreateCollectionModal = false"
                @created="collectionCreated"
            />

            <!-- Уведомления -->
            <div v-if="notificationMessage" class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-black bg-opacity-80 text-white px-4 py-2 rounded">
                {{ notificationMessage }}
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import AppLayout from "@/Layouts/AppLayout.vue"
import CommentsSection from '@/Components/Comments/CommentsSection.vue'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'

const page = usePage()
const artwork = ref(page.props.artwork)
const author = page.props.author
const collections = ref([...page.props.collections || []])
const notificationMessage = ref(null)

artwork.value.liked_by_user = artwork.value.liked_by_user || false
artwork.value.in_collections = artwork.value.in_collections || []

const showCollectionSelectorFlag = ref(false)
const showCreateCollectionModal = ref(false)
const selectedArt = ref(null)
const dropdownPosition = ref({ top: 0, left: 0 })

const authorWorks = ref([])
const loadingAuthorWorks = ref(false)
const hasMoreAuthorWorks = ref(true)
let authorPage = 1

const videoDuration = ref('')

// Загрузка других работ автора при монтировании
onMounted(() => {
    loadAuthorWorks()
})

// Функция для загрузки дополнительных работ автора
function loadAuthorWorks() {
    if (loadingAuthorWorks.value || !hasMoreAuthorWorks.value) return
    loadingAuthorWorks.value = true
    axios.get(`/author/${author.id}/works`, {
        params: {
            page: authorPage,
            per_page: 12 // Количество работ, подгружаемых за раз
        }
    })
        .then(res => {
            if (res.data.artworks && res.data.artworks.length > 0) {
                res.data.artworks.forEach(aw => {
                    // Убедитесь, что likes_count и liked_by_user присутствуют
                    aw.likes_count = aw.likes_count || 0
                    aw.liked_by_user = aw.liked_by_user || false
                })
                authorWorks.value.push(...res.data.artworks)
                authorPage++

                // Если количество подгруженных работ меньше per_page, значит больше нет
                if (res.data.artworks.length < 12) {
                    hasMoreAuthorWorks.value = false
                }
            } else {
                hasMoreAuthorWorks.value = false
            }
        })
        .catch(err => {
            console.error('Ошибка при загрузке дополнительных работ:', err)
        })
        .finally(() => {
            loadingAuthorWorks.value = false
        })
}

// Функция "Назад"
function goBack() {
    window.history.back()
}

// Функция лайка артворка
function likeArtwork() {
    axios.post(`/artworks/${artwork.value.id}/like`)
        .then(res => {
            console.log('Like response:', res.data)
            artwork.value.likes_count = res.data.likes_count
            artwork.value.liked_by_user = res.data.liked
            notificationMessage.value = res.data.liked ? 'Лайкнуто' : 'Лайк удален'
            setTimeout(() => notificationMessage.value = null, 3000)
        })
        .catch(err => {
            console.error('Ошибка при обновлении лайка:', err)
            notificationMessage.value = 'Ошибка при обновлении лайка'
            setTimeout(() => notificationMessage.value = null, 3000)
        })
}

// Открытие селектора коллекций для текущего артворка
function openCollectionSelector(e) {
    selectedArt.value = artwork.value
    const rect = e.currentTarget.getBoundingClientRect()
    dropdownPosition.value = { top: rect.bottom + window.scrollY, left: rect.left + window.scrollX }
    showCollectionSelectorFlag.value = true
}

// Открытие селектора коллекций из списка работ автора
function openCollectionSelectorFromList(aw) {
    selectedArt.value = aw
    // Позиционируем модальное окно в центре экрана
    dropdownPosition.value = { top: window.scrollY + window.innerHeight / 2, left: window.scrollX + window.innerWidth / 2 }
    showCollectionSelectorFlag.value = true
}

// Функция лайка из списка работ автора
function likeArtFromList(art) {
    axios.post(`/artworks/${art.id}/like`)
        .then(res => {
            console.log('Like response from list:', res.data)
            const idx = authorWorks.value.findIndex(a => a.id === art.id)
            if (idx >= 0) {
                authorWorks.value[idx].likes_count = res.data.likes_count
                authorWorks.value[idx].liked_by_user = res.data.liked
                notificationMessage.value = res.data.liked ? 'Лайкнуто' : 'Лайк удален'
                setTimeout(() => notificationMessage.value = null, 3000)
            }
        })
        .catch(err => {
            console.error('Ошибка при обновлении лайка из списка работ:', err)
            notificationMessage.value = 'Ошибка при обновлении лайка'
            setTimeout(() => notificationMessage.value = null, 3000)
        })
}

// Сохранение артворка в коллекцию
function saveArtToCollection(colIds) {
    axios.post(`/artworks/${selectedArt.value.id}/add-to-collection`, { collections: colIds })
        .then(res => {
            showCollectionSelectorFlag.value = false
            notificationMessage.value = 'Добавлено в коллекцию'
            setTimeout(() => { notificationMessage.value = null }, 3000)
            // Обновляем in_collections
            if (selectedArt.value.id === artwork.value.id) {
                artwork.value.in_collections = res.data.in_collections
            } else {
                const idx = authorWorks.value.findIndex(a => a.id === selectedArt.value.id)
                if (idx >= 0) {
                    authorWorks.value[idx].in_collections = res.data.in_collections
                }
            }
        })
        .catch(err => {
            console.error('Ошибка при добавлении в коллекцию:', err)
            notificationMessage.value = 'Ошибка при добавлении в коллекцию'
            setTimeout(() => { notificationMessage.value = null }, 3000)
        })
}

// Открытие модального окна создания коллекции
function createCollection() {
    showCreateCollectionModal.value = true
}

// Обработка события создания коллекции
function collectionCreated(col) {
    collections.value.push(col)
    showCreateCollectionModal.value = false
    notificationMessage.value = 'Коллекция создана'
    setTimeout(() => notificationMessage.value = null, 3000)
}

// Функция для установки продолжительности видео
function setVideoDuration(event) {
    const duration = event.target.duration
    videoDuration.value = formatDuration(duration)
}

// Форматирование продолжительности видео в формат mm:ss
function formatDuration(seconds) {
    const minutes = Math.floor(seconds / 60)
    const secs = Math.floor(seconds % 60)
    return `${minutes}:${secs < 10 ? '0' : ''}${secs}`
}

// Обновление количества комментариев после добавления нового
function updateCommentsCount(newCount) {
    console.log('updateCommentsCount called with:', newCount)
    artwork.value.comments_count = newCount
}
</script>

<style scoped>
/* Стили остаются без изменений */
/* Добавьте любые дополнительные стили при необходимости */
</style>
