<template>
    <AppLayout :title="`${collection.name} – Коллекция`">
        <div class="min-h-screen bg-base-200 dark:bg-base-800 text-base-content">
            <!-- Header Section -->
            <div class="relative overflow-hidden bg-base-300 dark:bg-base-900">

                <!-- Rotated Thumbnails Background -->
                <div class="absolute inset-0 flex justify-center items-center gap-4 opacity-30 pointer-events-none">
                    <img
                        v-for="(a, i) in artworks.slice(0, 5)"
                        :key="i"
                        :src="a.thumb_url || a.media[0]?.original_url || '/images/icons/collection-placeholder.svg'"
                        class="w-40 h-56 object-cover rounded-lg shadow-lg"
                        :style="`transform: rotate(${(i - 2) * 10}deg);`"
                    />
                </div>

                <!-- Gradient Overlay -->
                <div class="absolute inset-0 bg-gradient-to-b from-base-900/70 to-base-900/90"></div>

                <!-- Main Content -->
                <div class="relative z-10 flex flex-col md:flex-row items-center max-w-5xl mx-auto px-6 py-14 md:py-20">

                    <!-- Collection Cover -->
                    <img
                        :src="artworks[0]?.thumb_url || artworks[0]?.media[0]?.original_url || '/images/icons/collection-placeholder.svg'"
                        class="w-40 h-40 object-cover rounded-lg shadow-lg flex-shrink-0 mb-6 md:mb-0"
                        alt="Collection Cover"
                    />

                    <!-- Collection Info -->
                    <div class="md:ml-8 flex-1 text-center md:text-left">
            <span class="uppercase tracking-widest text-primary text-xs">
              Коллекция
            </span>
                        <h1 class="text-3xl sm:text-4xl font-bold leading-tight mt-1">
                            {{ collection.name }}
                        </h1>
                        <p class="text-sm text-base-content/70 mt-2">
                            {{ collection.artworks_count }} работ &middot;
                            создано {{ formattedCreatedAt }}
                        </p>

                        <!-- Author Info -->
                        <div class="flex items-center justify-center md:justify-start mt-4">
                            <img
                                :src="author.profile_photo_url"
                                class="w-10 h-10 rounded-full object-cover mr-3"
                                alt="Author Avatar"
                            />
                            <Link :href="`/profile/${author.id}`" class="text-secondary hover:underline text-sm">
                                {{ author.name }}
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Back Button -->
                <button
                    @click="goBack"
                    class="absolute top-4 left-4 bg-base-900/50 hover:bg-base-900/80 p-2 rounded-full z-20 transition"
                >
                    <ArrowLeftIcon class="w-5 h-5 text-base-content" />
                </button>
            </div>

            <!-- Artwork Grid -->
            <div class="p-4 bg-base-200 dark:bg-base-800">
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

            <!-- Collection Selector Modal -->
            <CollectionSelector
                v-if="showSelector"
                :collections="userCollections"
                :position="dropdownPos"
                :selected-collections="selectedArt.in_collections"
                @close="showSelector = false"
                @selected="saveArtToCollection"
                @createCollection="showCreateCol = true"
            />

            <!-- Create Collection Modal -->
            <CreateCollectionModal
                v-if="showCreateCol"
                @close="showCreateCol = false"
                @created="c => userCollections.push(c)"
            />

            <!-- Toast Notification -->
            <div
                v-if="toast"
                class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-base-900/80 text-base-content px-4 py-2 rounded-lg shadow-lg"
            >
                {{ toast }}
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'
import { useArtworkActions } from '@/stores/useArtworkActions'
import AppLayout from '@/Layouts/AppLayout.vue'
import MasonryGrid from '@/Components/MasonryGrid.vue'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'
import axios from 'axios'

// Props from server
const { props } = usePage()
const collection = props.collection
const author = props.author
const artworks = ref(props.artworks || [])
const userCollections = ref(props.userCollections || [])

// Pinia actions
const { openSelector, toggleLike, setCollections } = useArtworkActions()
onMounted(() => {
    setCollections(userCollections.value)
})

// State
const showSelector = ref(false)
const showCreateCol = ref(false)
const dropdownPos = ref({ top: 0, left: 0 })
const selectedArt = ref(null)
const toast = ref(null)

// Computed
const formattedCreatedAt = computed(() =>
    new Date(collection.created_at).toLocaleDateString()
)

// Methods
function goBack() {
    history.back()
}

function openCollectionSelector(art, rect) {
    selectedArt.value = art
    dropdownPos.value = {
        top: rect.bottom + window.scrollY,
        left: rect.left + window.scrollX,
    }
    openSelector(art, rect)
    showSelector.value = true
}

function saveArtToCollection(ids) {
    axios
        .post(`/artworks/${selectedArt.value.id}/add-to-collection`, { collections: ids })
        .then(({ data }) => {
            selectedArt.value.in_collections = data.in_collections
            showSelector.value = false
            toast.value = 'Добавлено в коллекцию'
            setTimeout(() => (toast.value = null), 3000)
        })
}

function likeArt(art) {
    toggleLike(art)
}
</script>

<style scoped>
/* Pulse animation for avatars */
@keyframes avatar-pulse {
    0%, 100% { transform: scale(1); opacity: .8 }
    50%      { transform: scale(1.08); opacity: 0 }
}

.animate-avatar-pulse {
    animation: avatar-pulse 2.8s ease-in-out infinite;
}

/* Shadow override if needed */
.shadow-lg {
    box-shadow: 0 12px 25px rgba(0, 0, 0, .35);
}
</style>
