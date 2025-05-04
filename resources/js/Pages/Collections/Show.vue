<template>
    <AppLayout :title="`${collection.name} – Коллекция`">
        <div class="min-h-screen bg-gray-900 text-white">
            <div class="relative overflow-hidden bg-gray-800">

                <!-- Background Image -->
                <div class="absolute top-0 left-1/2 -translate-x-1/2
              flex gap-4 opacity-8 pointer-events-none">
                    <img v-for="(a,i) in artworks.slice(0,5)"
                         :key="i"
                         :src="a.thumb_url || a.media[0]?.original_url
                         || '/images/icons/collection-placeholder.svg'"
                         class="w-40 h-56 object-cover rounded-lg shadow-lg"
                         :style="`transform: rotate(${(i-2)*10}deg)`">
                </div>

                <!-- Dark Bg -->
                <div class="absolute inset-0 bg-gradient-to-b from-black/70 to-black/90"></div>

                <!-- Content -->
                <div class="relative z-10 flex flex-col md:flex-row items-center max-w-5xl mx-auto px-6 py-14 md:py-20">

                    <!-- Main Img for Collection -->
                    <img
                        :src="artworks[0]?.thumb_url || artworks[0]?.media[0]?.original_url
                         || '/images/icons/collection-placeholder.svg'"
                        class="w-40 h-40 object-cover rounded-lg shadow-lg flex-shrink-0 mb-6 md:mb-0"
                    >

                    <!-- Info -->
                    <div class="md:ml-8 flex-1 text-center md:text-left">

                      <span class="uppercase tracking-widest text-purple-400 text-xs">
                        Коллекция
                      </span>

                        <h1 class="text-3xl sm:text-4xl font-bold leading-tight mt-1">
                            {{ collection.name }}
                        </h1>

                        <p class="text-sm text-gray-300 mt-2">
                            {{ collection.artworks_count }} работ &nbsp;·&nbsp;
                            создано {{ new Date(collection.created_at).toLocaleDateString() }}
                        </p>

                        <!-- Author -->
                        <div class="flex items-center justify-center md:justify-start mt-4">
                            <img :src="author.profile_photo_url"
                                 class="w-10 h-10 rounded-full object-cover mr-3">
                            <Link :href="`/profile/${author.id}`"
                                  class="text-blue-400 hover:underline text-sm">
                                {{ author.name }}
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- back-button -->
                <button @click="goBack"
                        class="absolute top-4 left-4 bg-black/50 hover:bg-black/80 p-2 rounded-full z-20">
                    <img src="/images/icons/back.svg" alt="" class="h-4 w-4">
                </button>
            </div>

            <!-- masonry -->
            <div class="p-4">
                <MasonryGrid :items="artworks">
                    <template #default="{ item }">
                        <ArtworkCard
                            :art="item"
                            @save="openCollectionSelector"
                            @like="likeArt"
                        />
                    </template>
                </MasonryGrid>
            </div>

            <!-- selector / create-modal / toast -->
            <CollectionSelector v-if="showSelector"
                                :collections="userCollections"
                                :position="dropdownPos"
                                :selected-collections="selectedArt.in_collections"
                                @close="showSelector=false"
                                @selected="saveArtToCollection"
                                @createCollection="showCreateCol=true"/>

            <CreateCollectionModal v-if="showCreateCol"
                                   @close="showCreateCol=false"
                                   @created="c => userCollections.push(c)" />

            <div v-if="toast"
                 class="fixed bottom-4 left-1/2 -translate-x-1/2 bg-black/80 px-4 py-2 rounded">
                {{ toast }}
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref, computed, onMounted } from 'vue'
import { usePage, Link }            from '@inertiajs/vue3'
import { useArtworkActions }  from '@/stores/useArtworkActions'

import AppLayout         from '@/Layouts/AppLayout.vue'
import MasonryGrid       from '@/Components/MasonryGrid.vue'
import ArtworkCard       from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import axios from 'axios'

/* ---------- данные ---------- */
const { props } = usePage()
const collection   = props.collection
const author       = props.author
const artworks     = ref(props.artworks || [])
const userCollections = ref(props.userCollections || [])

const { openSelector, toggleLike, setCollections } = useArtworkActions()
onMounted(()=> setCollections(userCollections.value))

/* ---------- модалки ---------- */
const showSelector  = ref(false)
const showCreateCol = ref(false)
const dropdownPos   = ref({top:0,left:0})
const selectedArt   = ref(null)
const toast         = ref(null)

/* ---------- методы ---------- */
function goBack(){ history.back() }



</script>

<style scoped>
@keyframes avatar-pulse {
    0%,100% { transform: scale(1);   opacity: .8 }
    50%     { transform: scale(1.08);opacity: 0  }
}
/* подключаем через “arbitrary” tailwind-class */
.animate-avatar-pulse {
    animation: avatar-pulse 2.8s ease-in-out infinite;
}
/* мягкая тень для превью-веера; можно убрать, если не нужно */
.shadow-lg { box-shadow: 0 12px 25px rgba(0,0,0,.35); }

</style>

