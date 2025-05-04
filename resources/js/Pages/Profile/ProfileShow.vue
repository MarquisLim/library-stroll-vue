<template>
    <AppLayout :title="`${profileUser.name} – Профиль`">
        <div class="p-4 bg-gray-900 text-white min-h-screen">

            <!-- ───────── аватар и имя ───────── -->
            <div class="flex flex-col items-center text-center mb-6">
                <img :src="profileUser.profile_photo_url" class="w-40 h-40 rounded-full object-cover mb-2" />
                <h1 class="text-2xl font-bold">{{ profileUser.name }}</h1>
                <p class="text-gray-400 text-sm">
                    Присоединился: {{ new Date(profileUser.created_at).toLocaleDateString() }}
                </p>

                <Link v-if="isOwner" :href="route('profile.show')" class="text-blue-400 hover:underline mt-1">
                    Настройки профиля
                </Link>
            </div>

            <!-- ───────── коллекции (слайдер) ───────── -->
            <h2 class="text-xl font-semibold mb-2">Коллекции</h2>

            <Swiper
                :modules="[Navigation, Pagination, Mousewheel]"
                :slides-per-view="'auto'"
                :space-between="16"
                navigation
                mousewheel
                class="mb-8"
            >
            <SwiperSlide v-for="col in collections" :key="col.id" class="!w-auto">
                <CollectionCard
                    :collection="col"
                    @edit="openEditModal"
                    @remove="confirmDeleteModal"
                    :is-owner="isOwner"
                />
            </SwiperSlide>
            </Swiper>

            <!-- ───────── вкладки ───────── -->
            <div class="flex justify-center space-x-4 mb-4">
                <button @click="currentTab='created'"
                        :class="tabClass('created')">Созданные</button>
                <button @click="currentTab='liked'; loadLikedArtworks()"
                        :class="tabClass('liked')">Лайкнутые</button>
            </div>

            <!-- ───────── созданные ───────── -->
            <MasonryGrid v-if="currentTab==='created'" :items="artworks">
                <template #default="{ item }">
                    <ArtworkCard :art="item"/>
                </template>
            </MasonryGrid>

            <!-- ───────── лайкнутые ───────── -->
            <div v-else-if="currentTab==='liked'">
               <p v-if="loadingLikes" class="text-center py-4">Загрузка лайкнутых работ…</p>
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
import { ref, onMounted }           from 'vue'
import { usePage, Link }            from '@inertiajs/vue3'
import { Inertia }                  from '@inertiajs/inertia'
import axios                        from 'axios'

import AppLayout           from '@/Layouts/AppLayout.vue'
import ArtworkCard         from '@/Components/Gallery/ArtworkCard.vue'
import MasonryGrid         from '@/Components/MasonryGrid.vue'
import CollectionCard      from '@/Components/Collections/CollectionCard.vue'
import EditCollectionModal from '@/Components/Collections/EditCollectionModal.vue'
import ConfirmDeleteModal  from '@/Components/Collections/ConfirmDeleteModal.vue'

import { Swiper, SwiperSlide } from 'swiper/vue'
import 'swiper/css'
import 'swiper/css/navigation'
import 'swiper/css/pagination'
import { Navigation, Pagination, Mousewheel } from 'swiper/modules'

/* ---------- Pinia ---------- */
import { useArtworkActions } from '@/stores/useArtworkActions'
const actions = useArtworkActions()
const { toggleLike, openSelector, setCollections,
    addNewCollection } = actions   // addNewCollection пригодится после сохранения

/* ---------- данные сервера ---------- */
const { props }   = usePage()
const profileUser = props.profileUser
const artworks    = props.artworks      || []
const collections = ref(props.collections || [])   // локальные для слайдера
const isOwner     = props.isOwner       || false

/* передаём все коллекции текущего юзера в Pinia
   (нужны селектору коллекций) */
if (props.userCollections) setCollections(props.userCollections)

/* ---------- вкладки ---------- */
const currentTab     = ref('created')
const likedArtworks  = ref([])
const loadingLikes   = ref(false)

function loadLikedArtworks () {
    if (likedArtworks.value.length || loadingLikes.value) return
    loadingLikes.value = true
    axios.get(`/profile/${profileUser.id}/likes`)
        .then(res => likedArtworks.value = res.data)
        .finally   (() => loadingLikes.value = false)
}
function tabClass(tab){
    return currentTab.value===tab
        ? 'border-b-2 border-blue-500 text-blue-400 pb-2'
        : 'text-gray-400 pb-2'
}

/* ---------- коллекции: редакт / удал ---------- */
const editingCollection  = ref(null)
const deletingCollection = ref(null)
const showEditModal      = ref(false)
const showDeleteModal    = ref(false)

function openEditModal(col){ editingCollection.value = {...col}; showEditModal.value = true }

function handleUpdate(updated){
    // PUT/PATCH на сервер
    axios.post(`/collections/${editingCollection.value.id}`, updated)
        .then(({data})=>{
            const i = collections.value.findIndex(c=>c.id===editingCollection.value.id)
            if(i>-1) collections.value[i] = {...collections.value[i], ...data.collection}
            showEditModal.value = false
        })
}

function confirmDeleteModal(col){ deletingCollection.value = col; showDeleteModal.value = true }

function handleDestroy(){
    axios.delete(`/collections/${deletingCollection.value.id}`)
        .then(()=>{
            collections.value = collections.value.filter(c=>c.id!==deletingCollection.value.id)
            showDeleteModal.value = false
        })
}

/* ---------- лайк слайдера (если вдруг нужен) ---------- */
function likeArtLocal(art){
    toggleLike(art)
}

</script>
