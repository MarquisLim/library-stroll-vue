<template>
    <!-- clickable anchor -->
    <a
        ref="rootEl"
        :href="draft ? '/studio' : `/artworks/${art.id}`"
        class="relative group break-inside-avoid block transition-transform duration-300 ease-in-out md:hover:scale-105"
        @mouseenter="hover = true"
        @mouseleave="hover = false"
        @touchstart="mobileHover = true"
        @touchend="mobileHover = false"
    >
        <!-- draft badge -->
        <span
            v-if="draft"
            class="absolute top-2 left-2 z-20 bg-error text-error-content text-xs px-2 py-0.5 rounded-full shadow"
        >Черновик</span>

        <!-- media -->
        <div class="relative">
            <template v-if="!showVideo">
                <img
                    :src="thumbOrOriginal"
                    class="w-full object-cover rounded"
                    loading="lazy"
                    :alt="art.title"
                />
            </template>
            <template v-else>
                <video
                    :poster="thumbOrOriginal"
                    :src="previewSrc"
                    preload="metadata"
                    class="w-full object-cover rounded"
                    muted
                    autoplay
                    loop
                    playsinline
                />
            </template>

            <!-- duration -->
            <span
                v-if="art.video_duration_formatted && (!showVideo || isMobile)"
                class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-[11px] px-1.5 py-0.5 rounded flex items-center"
            >
        ⏱ {{ art.video_duration_formatted }}
      </span>
        </div>

        <!-- dark overlay -->
        <div
            class="absolute inset-0 bg-black bg-opacity-0 md:group-hover:bg-opacity-50 transition-opacity pointer-events-none"
        ></div>

        <!-- desktop hover info -->
        <div
            class="absolute inset-0 hidden md:flex flex-col justify-between p-3 opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity"
        >
            <h3 class="text-white text-lg font-bold truncate" :title="art.title">
                {{ art.title }}
            </h3>
            <div class="flex justify-between items-center text-white">
                <div class="flex items-center space-x-2 overflow-hidden max-w-full">
                    <img
                        :src="art.user.profile_photo_url"
                        class="h-8 w-8 rounded-full object-cover flex-shrink-0"
                    />
                    <span class="text-sm font-medium truncate">{{ art.user.name }}</span>
                </div>
                <div
                    class="flex items-center space-x-2 text-sm font-semibold flex-shrink-0"
                >
                    <div class="flex items-center space-x-1">
                        <img src="/images/icons/views.svg" class="w-5 h-5" alt="views" />
                        <span>{{ art.views_count || 0 }}</span>
                    </div>
                    <div class="flex items-center space-x-1">
                        <img
                            :src="
                art.liked_by_user
                  ? '/images/icons/liked.svg'
                  : '/images/icons/like.svg'
              "
                            class="w-5 h-5"
                            alt="likes"
                        />
                        <span>{{ art.likes_count || 0 }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- desktop hover buttons -->
        <div
            class="absolute top-2 right-2 hidden md:flex flex-col space-y-2 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"
        >
            <button
                class="pointer-events-auto rounded-full bg-black bg-opacity-60 hover:bg-opacity-80 text-white w-9 h-9 flex items-center justify-center"
                @click.stop.prevent="saveToCollection"
                @mouseenter="animateButton('collection')"
                @mouseleave="resetButton('collection')"
            >
                <img
                    src="/images/icons/plus-btn-white.svg"
                    class="w-5 h-5"
                    :class="collectionAnim"
                />
            </button>
            <button
                class="pointer-events-auto rounded-full bg-black bg-opacity-60 hover:bg-opacity-80 text-white w-9 h-9 flex items-center justify-center"
                @click.stop.prevent="likeWithAnimation"
                @mouseenter="animateButton('like')"
                @mouseleave="resetButton('like')"
            >
                <img
                    :src="art.liked_by_user ? '/images/icons/liked.svg' : '/images/icons/like.svg'"
                    class="w-5 h-5"
                    :class="likeAnim"
                />
            </button>
        </div>

        <!-- mobile footer -->
        <div
            class="block md:hidden bg-white/90 dark:bg-black/60 text-base-content dark:text-white px-2 py-1 rounded-b flex items-center justify-between shadow"
        >
            <div class="flex items-center space-x-2 overflow-hidden pr-1 flex-1">
                <img
                    :src="art.user.profile_photo_url"
                    class="h-6 w-6 rounded-full object-cover flex-shrink-0"
                />
                <div class="min-w-0">
                    <p class="text-sm font-semibold truncate" :title="art.title">
                        {{ art.title }}
                    </p>
                    <p class="text-[11px] truncate">{{ art.user.name }}</p>
                </div>
            </div>
            <div class="flex items-center space-x-2 mr-1">
                <button
                    class="flex items-center space-x-0.5 text-[16px]"
                    @click.stop.prevent="likeWithAnimation"
                >
                    <img
                        :src="art.liked_by_user ? '/images/icons/liked.svg' : '/images/icons/like.svg'"
                        class="w-6 h-6"
                    />
                    <span>{{ art.likes_count || 0 }}</span>
                </button>
                <div class="flex items-center space-x-0.5 text-[16px]">
                    <img src="/images/icons/views.svg" class="w-6 h-6" />
                    <span>{{ art.views_count || 0 }}</span>
                </div>
                <button class="flex items-center" @click.stop.prevent="saveToCollection">
                    <img src="/images/icons/plus-btn-white.svg" class="w-6 h-6" />
                </button>
            </div>
        </div>
    </a>
</template>

<script setup>
import { ref, computed, defineProps, onMounted, onBeforeUnmount } from 'vue'
import { useArtworkActions } from '@/stores/useArtworkActions'

const props = defineProps({ art: Object })

const { toggleLike, openSelector } = useArtworkActions()

const hover = ref(false)
const mobileHover = ref(false)
const inView = ref(false)
const isMobile = ref(false)
const rootEl = ref(null)

function updateIsMobile() {
    isMobile.value = window.matchMedia('(max-width: 767px)').matches
}
updateIsMobile()
window.addEventListener('resize', updateIsMobile)

let observer
onMounted(() => {
    if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver(
            entries => {
                entries.forEach(e => (inView.value = e.isIntersecting))
            },
            { threshold: 0.6 }
        )
        if (rootEl.value) observer.observe(rootEl.value)
    }
})
onBeforeUnmount(() => {
    observer && observer.disconnect()
    window.removeEventListener('resize', updateIsMobile)
})

const isVideo = computed(() => props.art.type === 'video')
const previewExists = computed(() => !!props.art.preview_url)
const showVideo = computed(() => {
    if (!isVideo.value || !previewExists.value) return false
    return isMobile.value ? mobileHover.value || inView.value : hover.value
})

const thumbOrOriginal = computed(() => {
    const m = props.art.media[0]
    if (!m) return null
    return props.art.thumb_url
        ? `${props.art.thumb_url}?v=${m.updated_at}`
        : `${m.original_url}?v=${m.updated_at}`
})
const previewSrc = computed(() =>
    previewExists.value
        ? `${props.art.preview_url}?v=${props.art.media[0].updated_at}`
        : null
)

function saveToCollection(evt) {
    openSelector(props.art, evt.currentTarget.getBoundingClientRect())
}

const collectionAnim = ref('')
const likeAnim = ref('')
function likeWithAnimation() {
    toggleLike(props.art)
    likeAnim.value = 'animate-like'
    setTimeout(() => (likeAnim.value = ''), 450)
}
function animateButton(t) {
    if (t === 'collection') collectionAnim.value = 'animate-shake'
    if (t === 'like') likeAnim.value = 'animate-shake'
}
function resetButton(t) {
    if (t === 'collection') collectionAnim.value = ''
    if (t === 'like') likeAnim.value = ''
}

const draft = computed(() => !props.art.is_published)
</script>

<style scoped>
@keyframes shake {
    0%,
    100% {
        transform: translateX(0);
    }
    20% {
        transform: translateX(-2px);
    }
    40% {
        transform: translateX(2px);
    }
    60% {
        transform: translateX(-2px);
    }
    80% {
        transform: translateX(2px);
    }
}
.animate-shake {
    animation: shake 0.4s ease;
}
@keyframes like {
    0% {
        transform: scale(1);
    }
    25% {
        transform: scale(1.2);
    }
    50% {
        transform: scale(0.9);
    }
    75% {
        transform: scale(1.05);
    }
    100% {
        transform: scale(1);
    }
}
.animate-like {
    animation: like 0.4s ease;
}
</style>
