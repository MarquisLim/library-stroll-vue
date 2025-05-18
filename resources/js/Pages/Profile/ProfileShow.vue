<template>
    <AppLayout :title="`${profileUser.name} – Профиль`">
        <div class="p-4 bg-base-100 text-base-content min-h-screen">

            <!-- ───────── аватар и имя ───────── -->
            <div class="flex flex-col items-center text-center mb-6">
                <img :src="profileUser.profile_photo_url"
                     class="w-40 h-40 rounded-full object-cover mb-2 ring ring-primary/50"/>
                <h1 class="text-2xl font-bold text-primary">{{ profileUser.name }}</h1>
                <p class="text-base-content/70 text-sm">
                    Присоединился: {{ new Date(profileUser.created_at).toLocaleDateString() }}
                </p>

                <Link v-if="isOwner"
                      :href="route('profile.show')"
                      class="mt-1 text-secondary hover:text-secondary-focus underline">
                    Настройки профиля
                </Link>
            </div>
            <div v-if="collections.length">
                <!-- ───────── коллекции (слайдер) ───────── -->
                <h2 class="text-xl font-semibold text-primary mb-2">Коллекции</h2>
                <div class="relative mb-8">
                    <Swiper
                        :modules="[Navigation, Pagination, Mousewheel]"
                        :slides-per-view="'auto'"
                        :space-between="16"
                        mousewheel
                        class="pb-4"
                    >
                        <SwiperSlide v-for="col in collections" :key="col.id" class="!w-auto">
                            <CollectionCard
                                :collection="col"
                                @edit="openEditModal"
                                @remove="confirmDeleteModal"
                                :is-owner="isOwner"
                            />
                        </SwiperSlide>

                        <!-- кастомные кнопки навигации -->
                        <template #navigation-prev>
                            <button class="swiper-button-prev btn btn-circle btn-sm bg-primary text-primary-content opacity-70 hover:opacity-100">
                                <ChevronLeftIcon class="w-5 h-5"/>
                            </button>
                        </template>
                        <template #navigation-next>
                            <button class="swiper-button-next btn btn-circle btn-sm bg-primary text-primary-content opacity-70 hover:opacity-100">
                                <ChevronRightIcon class="w-5 h-5"/>
                            </button>
                        </template>
                    </Swiper>
                </div>
            </div>

            <!-- ───────── вкладки ───────── -->
            <div class="flex justify-center gap-4 mb-4">
                <button @click="currentTab='created'"
                        :class="tabClass('created')">
                    Созданные
                </button>
                <button @click="currentTab='liked'; loadLikedArtworks()"
                        :class="tabClass('liked')">
                    Лайкнутые
                </button>
            </div>

            <!-- ───────── созданные ───────── -->
            <MasonryGrid v-if="currentTab==='created'" :items="artworks">
                <template #default="{ item }">
                    <ArtworkCard :art="item"/>
                </template>
            </MasonryGrid>

            <!-- ───────── лайкнутые ───────── -->
            <div v-else-if="currentTab==='liked'">
                <p v-if="loadingLikes" class="text-center py-4 text-base-content/60">
                    Загрузка лайкнутых работ…
                </p>
                <MasonryGrid v-else :items="likedArtworks">
                    <template #default="{ item }">
                        <ArtworkCard :art="item"/>
                    </template>
                </MasonryGrid>
            </div>

            <!-- ───────── модалки редакт/удал ───────── -->
            <EditCollectionModal
                v-if="showEditModal"
                :initial-collection="editingCollection"
                @close="showEditModal=false"
                @saved="handleUpdate"
            />

            <ConfirmDeleteModal
                v-if="showDeleteModal"
                :collection="deletingCollection"
                @close="showDeleteModal=false"
                @confirmed="handleDestroy"
            />
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'
import axios             from 'axios'
import AppLayout         from '@/Layouts/AppLayout.vue'
import MasonryGrid       from '@/Components/MasonryGrid.vue'
import ArtworkCard       from '@/Components/Gallery/ArtworkCard.vue'
import CollectionCard    from '@/Components/Collections/CollectionCard.vue'
import EditCollectionModal from '@/Components/Collections/EditCollectionModal.vue'
import ConfirmDeleteModal  from '@/Components/Collections/ConfirmDeleteModal.vue'
import { Swiper, SwiperSlide } from 'swiper/vue'
import 'swiper/css'
import 'swiper/css/navigation'
import 'swiper/css/pagination'
import { Navigation, Pagination, Mousewheel } from 'swiper/modules'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'

/* Pinia store */
import { useArtworkActions } from '@/stores/useArtworkActions'
const { setCollections, toggleLike } = useArtworkActions()

/* server props */
const { props }      = usePage()
const profileUser    = props.profileUser
const artworks       = props.artworks || []
const collections    = ref(props.collections || [])
const isOwner        = props.isOwner || false

/* initialize Pinia collections for selector */
if (props.userCollections) {
    setCollections(props.userCollections)
}

/* tabs */
const currentTab    = ref('created')
const likedArtworks = ref([])
const loadingLikes  = ref(false)

function loadLikedArtworks() {
    if (likedArtworks.value.length || loadingLikes.value) return
    loadingLikes.value = true
    axios.get(`/profile/${profileUser.id}/likes`)
        .then(res => likedArtworks.value = res.data)
        .finally(() => loadingLikes.value = false)
}

/* tab styling */
function tabClass(tab) {
    return [
        'px-4 py-2 font-medium transition',
        currentTab.value === tab
            ? 'border-b-2 border-primary text-primary'
            : 'text-base-content/60 hover:text-base-content'
    ].join(' ')
}

/* edit / delete collection */
const editingCollection  = ref(null)
const deletingCollection = ref(null)
const showEditModal      = ref(false)
const showDeleteModal    = ref(false)

function openEditModal(col) {
    editingCollection.value = { ...col }
    showEditModal.value = true
}
function handleUpdate(updated) {
    axios.post(`/collections/${editingCollection.value.id}`, updated)
        .then(({ data }) => {
            const i = collections.value.findIndex(c => c.id === editingCollection.value.id)
            if (i > -1) collections.value[i] = data.collection
            showEditModal.value = false
        })
}
function confirmDeleteModal(col) {
    deletingCollection.value = col
    showDeleteModal.value = true
}
function handleDestroy() {
    axios.delete(`/collections/${deletingCollection.value.id}`)
        .then(() => {
            collections.value = collections.value.filter(c => c.id !== deletingCollection.value.id)
            showDeleteModal.value = false
        })
}

/* optional local like */
function likeArtLocal(art) {
    toggleLike(art)
}
</script>
