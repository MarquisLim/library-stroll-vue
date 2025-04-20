<template>
    <div
        class="relative group mb-2 break-inside-avoid cursor-pointer transition-opacity duration-500 ease-in-out transform hover:scale-105 appear"
        @click="goToArtwork"
        @mouseenter="hover = true"
        @mouseleave="hover = false"
    >
        <div class="w-full h-0 pb-[100%] relative"> <!-- Фиксированное соотношение сторон (квадрат) -->
            <!-- Изображение -->
            <img
                v-if="!isVideo"
                :src="thumbOrOriginal"
                class="absolute top-0 left-0 w-full h-full object-cover rounded"
                loading="lazy"
                :alt="art.title"
            />

            <!-- Видео -->
            <template v-else class="absolute top-0 left-0 w-full h-full">
                <video
                    v-if="hover && previewExists"
                    :src="previewSrc"
                    class="absolute inset-0 w-full h-full object-cover rounded"
                    muted autoplay loop playsinline
                ></video>
                <!-- Показ первого кадра видео -->
                <img
                    v-else
                    :src="thumbOrOriginal"
                    class="absolute top-0 left-0 w-full h-full object-cover rounded"
                    loading="lazy"
                    :alt="art.title"
                />

                <span
                    v-if="isVideo && !hover && art.video_duration_formatted"
                    class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs px-2 py-1 rounded flex items-center"
                >
                    ⏱ {{ art.video_duration_formatted }}
                </span>
            </template >
        </div>

        <!-- Overlay при hover -->
        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-opacity"></div>

        <!-- Появляется только при hover -->
        <div class="absolute inset-0 flex flex-col justify-between p-2 opacity-0 group-hover:opacity-100 pointer-events-none">
            <div class="flex justify-between">
                <!-- Добавить в коллекцию -->
                <button
                    @click.stop="openCollections($event)"
                    class="relative rounded-full bg-black bg-opacity-50 text-white w-12 h-12 flex items-center justify-center hover:bg-opacity-80 pointer-events-auto focus:outline-none"
                    @mouseenter="animateButton('collection')"
                    @mouseleave="resetButton('collection')"
                >
                    <img src="/images/icons/plus-btn.svg" alt="plus" class="w-8 h-8 transition-transform duration-300" :class="collectionAnim"/>
                </button>

                <!-- Лайк -->
                <button
                    @click.stop="likeArtWithAnimation(art)"
                    class="relative rounded-full w-12 h-12 flex items-center justify-center pointer-events-auto focus:outline-none"
                    :class="art.liked_by_user ? 'bg-black bg-opacity-70' : 'bg-black bg-opacity-50 hover:bg-opacity-80 text-white'"
                    @mouseenter="animateButton('like')"
                    @mouseleave="resetButton('like')"
                >
                    <img :src="art.liked_by_user ? '/images/icons/liked.svg' : '/images/icons/like.svg'" alt="like" class="w-8 h-8 transition-transform duration-300" :class="likeAnim"/>
                </button>
            </div>

            <div class="flex justify-between items-center text-white pointer-events-none">
                <div class="flex items-center space-x-1">
                    <img :src="art.user.profile_photo_url" class="h-6 w-6 rounded-full object-cover"/>
                    <span class="text-sm">{{ art.user.name }}</span>
                </div>
                <div class="text-sm">
                    👁{{ art.views_count || 0 }} | ❤️{{ art.likes_count || 0 }}
                    <span v-if="isVideo" class="ml-2">{{ videoDuration }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { defineProps, defineEmits, computed, ref } from 'vue'

const props = defineProps({
    art: Object
})
const hover = ref(false)

const emit = defineEmits(['save', 'like'])

const collectionAnim = ref('')
const likeAnim = ref('')

const isVideo = computed(() => props.art.type === 'video')

const previewExists = computed(() => !!props.art.preview_url)  // ← ИЗМЕНИЛ

const thumbOrOriginal = computed(() => {
    const m = props.art.media[0]
    if (!m) return null
    if (props.art.thumb_url) {
        return `${props.art.thumb_url}?v=${m.updated_at}`
    }
    return `${m.original_url}?v=${m.updated_at}`
})
const previewSrc = computed(() => {
    if (!previewExists.value) return null
    return `${props.art.preview_url}?v=${props.art.media[0].updated_at}`
})
if (props.art.media[0]?.type === 'video') {
    isVideo.value = true
}

function goToArtwork() {
    window.location.href = `/artworks/${props.art.id}`
}

function openCollections(event) {
    emit('save', { art: props.art, event: event })
}

function likeArtWithAnimation(art) {
    emit('like', art)
    // Запускаем анимацию лайка
    likeAnim.value = 'animate-like'
    setTimeout(() => {
        likeAnim.value = ''
    }, 500)
}

function animateButton(button) {
    if(button === 'collection'){
        collectionAnim.value = 'animate-shake'
    } else if(button === 'like'){
        likeAnim.value = 'animate-shake'
    }
}

function resetButton(button) {
    if(button === 'collection'){
        collectionAnim.value = ''
    } else if(button === 'like'){
        likeAnim.value = ''
    }
}

function formatDuration(seconds) {
    const minutes = Math.floor(seconds / 60)
    const secs = Math.floor(seconds % 60)
    return `${minutes}:${secs < 10 ? '0' : ''}${secs}`
}
</script>

<style scoped>
/* Плавное появление артов */
.appear {
    opacity: 1;
    transform: translateY(0);
}

/* Изначальное состояние для плавного появления */
.grid > *,
.columns-1 > *,
.columns-2 > *,
.columns-3 > *,
.columns-4 > *,
.columns-5 > *,
.columns-6 > * {
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.5s ease, transform 0.5s ease;
}

.grid > *.appear,
.columns-1 > *.appear,
.columns-2 > *.appear,
.columns-3 > *.appear,
.columns-4 > *.appear,
.columns-5 > *.appear,
.columns-6 > *.appear {
    opacity: 1;
    transform: translateY(0);
}

/* Анимация для кнопок при наведении - легкое потрясывание */
@keyframes shake {
    0% { transform: translateX(0); }
    20% { transform: translateX(-2px); }
    40% { transform: translateX(2px); }
    60% { transform: translateX(-2px); }
    80% { transform: translateX(2px); }
    100% { transform: translateX(0); }
}
.animate-shake {
    animation: shake 0.5s ease;
}

/* Анимация для лайка при клике */
@keyframes like {
    0% { transform: scale(1); }
    25% { transform: scale(1.2); }
    50% { transform: scale(0.9); }
    75% { transform: scale(1.05); }
    100% { transform: scale(1); }
}
.animate-like {
    animation: like 0.5s ease;
}

/* Удаление иконки видео рядом с ником пользователя */
/* Удален стиль, добавлявший иконку видео через ::before */

/* Дополнительные стили для стабильного заполнения */
.w-full.h-full.object-cover {
    object-fit: cover;
}

.relative {
    position: relative;
}

.absolute {
    position: absolute;
}

/* Убедитесь, что контейнер имеет фиксированное соотношение сторон */
.w-full.h-0.pb-[100%] {
    padding-bottom: 100%;
}
</style>
