<template>
    <div
        class="relative group mb-2 break-inside-avoid cursor-pointer transition-opacity duration-500 ease-in-out transform hover:scale-105"
        @click="goToArtwork"
        @mouseenter="hover = true"
        @mouseleave="hover = false"
    >
        <!-- ------------------- медиа ------------------- -->
        <div>
            <!-- Изображение -->
            <img
                v-if="!isVideo"
                :src="thumbOrOriginal"
                class="w-full object-cover rounded"
                loading="lazy"
                :alt="art.title"
            />

            <!-- Видео -->
            <template v-else>
                <video
                    v-if="hover && previewExists"
                    :poster="thumbOrOriginal"
                    :src="previewSrc"
                    preload="metadata"
                    class="w-full object-cover rounded"
                    muted autoplay loop playsinline
                />
                <img
                    v-else
                    :src="thumbOrOriginal"
                    class="w-full object-cover rounded"
                    loading="lazy"
                    :alt="art.title"
                />

                <span
                    v-if="!hover && art.video_duration_formatted"
                    class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs px-2 py-1 rounded flex items-center"
                >
          ⏱ {{ art.video_duration_formatted }}
        </span>
            </template>
        </div>

        <!-- затемнение -->
        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-opacity" />

        <!-- hover-панель -->
        <div
            class="absolute inset-0 flex flex-col justify-between p-2 opacity-0 group-hover:opacity-100 pointer-events-none"
        >
            <div class="flex justify-between">
                <!-- в коллекцию -->
                <button
                    class="relative rounded-full bg-black bg-opacity-50 text-white w-12 h-12 flex items-center justify-center hover:bg-opacity-80 pointer-events-auto"
                    @click.stop="saveToCollection"
                    @mouseenter="animateButton('collection')"
                    @mouseleave="resetButton('collection')"
                >
                    <img src="/images/icons/plus-btn.svg" class="w-8 h-8 transition-transform duration-300" :class="collectionAnim" />
                </button>

                <!-- лайк -->
                <button
                    class="relative rounded-full w-12 h-12 flex items-center justify-center pointer-events-auto"
                    :class="art.liked_by_user ? 'bg-black bg-opacity-70' : 'bg-black bg-opacity-50 hover:bg-opacity-80 text-white'"
                    @click.stop="likeWithAnimation"
                    @mouseenter="animateButton('like')"
                    @mouseleave="resetButton('like')"
                >
                    <img :src="art.liked_by_user ? '/images/icons/liked.svg' : '/images/icons/like.svg'" class="w-8 h-8 transition-transform duration-300" :class="likeAnim" />
                </button>
            </div>

            <!-- подпись -->
            <div class="flex justify-between items-center text-white pointer-events-none">
                <div class="flex items-center space-x-1">
                    <img :src="art.user.profile_photo_url" class="h-6 w-6 rounded-full object-cover" />
                    <span class="text-sm">{{ art.user.name }}</span>
                </div>
                <div class="text-sm">
                    👁{{ art.views_count || 0 }} | ❤️{{ art.likes_count || 0 }}
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, defineProps } from 'vue'
import { useArtworkActions } from '@/stores/useArtworkActions'

/* ---------- пропсы ---------- */
const props = defineProps({ art: Object })

/* ---------- store ---------- */
const { toggleLike, openSelector } = useArtworkActions()

/* ---------- локальное состояние ---------- */
const hover          = ref(false)
const collectionAnim = ref('')
const likeAnim       = ref('')

/* ---------- вычисляемые ---------- */
const isVideo = computed(() => props.art.type === 'video')

const previewExists = computed(() => !!props.art.preview_url)

const thumbOrOriginal = computed(() => {
    const m = props.art.media[0]
    if (!m) return null
    return props.art.thumb_url
        ? `${props.art.thumb_url}?v=${m.updated_at}`
        : `${m.original_url}?v=${m.updated_at}`
})
const previewSrc = computed(() => previewExists.value
    ? `${props.art.preview_url}?v=${props.art.media[0].updated_at}`
    : null)

/* ---------- методы ---------- */
function goToArtwork () {
    window.location.href = `/artworks/${props.art.id}`
}

function saveToCollection (evt) {
    openSelector(props.art, evt.currentTarget.getBoundingClientRect())
}

function likeWithAnimation () {
    toggleLike(props.art)
    likeAnim.value = 'animate-like'
    setTimeout(() => (likeAnim.value = ''), 500)
}

function animateButton (type) {
    if (type === 'collection') collectionAnim.value = 'animate-shake'
    if (type === 'like')       likeAnim.value       = 'animate-shake'
}
function resetButton (type) {
    if (type === 'collection') collectionAnim.value = ''
    if (type === 'like')       likeAnim.value       = ''
}
</script>

<style scoped>
@keyframes shake {
    0%   { transform: translateX(0) }
    20%  { transform: translateX(-2px) }
    40%  { transform: translateX( 2px) }
    60%  { transform: translateX(-2px) }
    80%  { transform: translateX( 2px) }
    100% { transform: translateX(0) }
}
.animate-shake { animation: shake 0.5s ease }

@keyframes like {
    0%   { transform: scale(1) }
    25%  { transform: scale(1.2) }
    50%  { transform: scale(0.9) }
    75%  { transform: scale(1.05) }
    100% { transform: scale(1) }
}
.animate-like { animation: like 0.5s ease }
</style>
