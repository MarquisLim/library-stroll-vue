<script setup>
import { ArrowUpRightIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { Link } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { assistantPreviewUrl } from '../../composables/assistantReplies.js'

const props = defineProps({ artwork: { type: Object, required: true } })
const previewUrl = ref('')
const unavailable = ref(false)
const loaded = ref(false)
watch(() => props.artwork, artwork => {
    loaded.value = false
    unavailable.value = false
    previewUrl.value = assistantPreviewUrl(artwork.artwork_id)
}, { immediate: true })

function imageEvent(event, success) {
    if (event.target.dataset.preview !== previewUrl.value) return
    loaded.value = success
    unavailable.value = !success
}
</script>

<template>
    <Link
        :href="artwork.url"
        class="group relative flex h-full flex-col overflow-hidden rounded-2xl border border-base-300 bg-base-100 transition-colors hover:border-primary/60 hover:bg-primary/5"
    >
        <img
            v-if="!unavailable"
            :src="previewUrl"
            :key="previewUrl"
            :data-preview="previewUrl"
            alt=""
            loading="lazy"
            decoding="async"
            class="w-full border-b border-base-300/70 bg-base-200 object-contain"
            :class="loaded ? 'h-40 sm:h-44' : 'absolute h-px w-px opacity-0'"
            @load="imageEvent($event, true)"
            @error="imageEvent($event, false)"
        >
        <span class="flex min-w-0 flex-1 items-start gap-3 p-3.5">
            <span v-if="!loaded || artwork.number" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-primary/10 text-sm font-bold text-primary">
                <span v-if="artwork.number" :aria-label="`Источник ${artwork.number}`">{{ artwork.number }}</span>
                <PhotoIcon v-else class="h-5 w-5" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-bold leading-snug [overflow-wrap:anywhere]">{{ artwork.title || 'Без названия' }}</span>
                <span class="mt-1 block text-xs font-semibold text-base-content/65 group-hover:text-primary">Открыть работу</span>
            </span>
            <ArrowUpRightIcon class="mt-1 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
        </span>
    </Link>
</template>
