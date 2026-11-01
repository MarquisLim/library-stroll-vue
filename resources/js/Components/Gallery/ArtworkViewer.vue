<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import {
    ArrowsPointingOutIcon,
    ArrowsPointingInIcon,
    ArrowPathIcon,
    PhotoIcon,
} from "@heroicons/vue/24/outline";
const props = defineProps({
    src: String,
    title: String,
    type: String,
    mimeType: String,
    allowDownload: Boolean,
});
const frame = ref(null);
const loaded = ref(false);
const failed = ref(false);
const attempt = ref(0);
const fullscreen = ref(false);
const fullscreenError = ref("");
const isVideo = computed(() => props.type === "video");
watch(
    () => props.src,
    () => {
        loaded.value = false;
        failed.value = false;
        attempt.value++;
    }
);
function retry() {
    loaded.value = false;
    failed.value = false;
    attempt.value++;
}
function onFullscreenChange() {
    fullscreen.value = document.fullscreenElement === frame.value;
}
async function toggleFullscreen() {
    fullscreenError.value = "";
    try {
        if (fullscreen.value) await document.exitFullscreen();
        else await frame.value?.requestFullscreen();
    } catch {
        fullscreenError.value = "Не удалось открыть полноэкранный режим.";
    }
}
onMounted(() =>
    document.addEventListener("fullscreenchange", onFullscreenChange)
);
onUnmounted(() =>
    document.removeEventListener("fullscreenchange", onFullscreenChange)
);
</script>

<template>
    <div
        ref="frame"
        class="artwork-viewer"
        :aria-busy="!!src && !loaded && !failed"
    >
        <div
            class="absolute inset-x-0 top-0 z-10 flex items-center justify-between gap-3 p-4 sm:p-5"
        >
            <span
                class="rounded-full border border-base-300 bg-base-100/90 px-3 py-1.5 text-xs font-semibold text-base-content/65"
                >{{ isVideo ? "Видео" : "Изображение" }}</span
            ><button
                v-if="src && !failed"
                type="button"
                class="btn btn-circle btn-sm border-base-300 bg-base-100/90 text-base-content shadow-sm"
                :aria-label="
                    fullscreen
                        ? 'Выйти из полноэкранного режима'
                        : 'На весь экран'
                "
                @click="toggleFullscreen"
            >
                <component
                    :is="
                        fullscreen
                            ? ArrowsPointingInIcon
                            : ArrowsPointingOutIcon
                    "
                    class="h-4 w-4"
                />
            </button>
        </div>
        <div
            v-if="!src || failed"
            class="relative z-10 flex min-h-80 flex-col items-center justify-center gap-4 p-8 text-center"
            role="status"
        >
            <PhotoIcon class="h-10 w-10 text-base-content/30" />
            <p class="font-semibold">
                {{
                    src ? "Не удалось загрузить файл" : "Файл работы недоступен"
                }}
            </p>
            <p v-if="src" class="max-w-xs text-sm text-base-content/55">
                Проверьте соединение и попробуйте ещё раз.
            </p>
            <button v-if="src" class="btn btn-outline btn-sm" @click="retry">
                <ArrowPathIcon class="h-4 w-4" />Повторить загрузку
            </button>
        </div>
        <template v-else>
            <div
                v-if="!loaded"
                class="absolute inset-0 flex items-center justify-center"
                role="status"
            >
                <span class="loading loading-spinner text-primary" /><span
                    class="sr-only"
                    >Загружаем работу</span
                >
            </div>
            <video
                v-if="isVideo"
                :key="attempt"
                class="artwork-viewer-media"
                controls
                playsinline
                preload="metadata"
                :controlslist="allowDownload ? undefined : 'nodownload'"
                :aria-label="title || 'Видео работы'"
                @loadedmetadata="loaded = true"
                @error="failed = true"
            >
                <source :src="src" :type="mimeType || undefined" @error="failed = true" />
            </video>
            <img
                v-else
                :key="attempt"
                :src="src"
                :alt="title || 'Изображение работы'"
                class="artwork-viewer-media"
                :class="{ 'opacity-0': !loaded }"
                draggable="false"
                decoding="async"
                @load="loaded = true"
                @error="failed = true"
                @contextmenu.prevent
            />
            <span
                v-if="loaded && !isVideo"
                class="pointer-events-none absolute bottom-5 left-5 select-none rounded-full bg-base-100/70 px-3 py-1 text-[10px] font-semibold tracking-wide text-base-content/50"
                >© LibraryStroll</span
            >
        </template>
        <p
            v-if="fullscreenError"
            class="absolute bottom-4 right-4 z-20 rounded-xl bg-base-100 p-3 text-xs"
            role="alert"
        >
            {{ fullscreenError }}
        </p>
    </div>
</template>

<style scoped>
.artwork-viewer {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 0;
    min-height: 440px;
    padding: 72px 24px 52px;
    overflow: hidden;
    border-radius: 24px;
    background-color: rgb(var(--rgb-base-200));
    background-image: radial-gradient(
        rgb(var(--rgb-base-content) / 0.07) 0.75px,
        transparent 0.75px
    );
    background-size: 12px 12px;
}
.artwork-viewer-media {
    display: block;
    width: 100%;
    max-height: min(74vh, 900px);
    object-fit: contain;
    transition: opacity 0.2s;
}
.artwork-viewer:fullscreen {
    width: 100%;
    height: 100%;
    border-radius: 0;
    padding: 72px 24px;
}
.artwork-viewer:fullscreen .artwork-viewer-media {
    max-height: calc(100dvh - 144px);
}
@media (max-width: 639px) {
    .artwork-viewer {
        min-height: 300px;
        padding: 64px 12px 44px;
        border-radius: 20px;
    }
    .artwork-viewer-media {
        max-height: 65vh;
    }
}
@media (prefers-reduced-motion: reduce) {
    .artwork-viewer-media {
        transition: none;
    }
}
</style>
