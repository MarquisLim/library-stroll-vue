<script setup>
import { ArrowUpRightIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { Link } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { assistantPreviewUrl } from '../../composables/assistantReplies.js'

const props = defineProps({ artwork: { type: Object, required: true }, diagnostics: Object })
const metadataLabels = {
    summary: 'Vision Summary', objects: 'Objects', themes: 'Themes', styles: 'Styles', colors: 'Colors',
    mood: 'Mood', tags_ru: 'Tags RU', tags_en: 'Tags EN', analysis_source: 'Источник анализа',
}
const score = (value, precision) => Number.isFinite(value) ? value.toFixed(precision) : '—'
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
    <div class="flex h-full min-w-0 flex-col">
    <Link
        :href="artwork.url"
        class="group relative flex flex-col overflow-hidden rounded-2xl border border-base-300 bg-base-100 transition-colors hover:border-primary/60 hover:bg-primary/5"
        :class="diagnostics ? 'shrink-0' : 'flex-1'"
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
    <div v-if="diagnostics" class="mt-1.5 rounded-xl border border-base-300 bg-base-100 px-3 py-2 text-xs">
        <dl class="grid grid-cols-[1fr_auto] gap-x-2 gap-y-1 tabular-nums">
            <dt>Dense Score</dt><dd>{{ score(diagnostics.dense_score, 3) }}</dd>
            <dt>Dense Rank</dt><dd>{{ diagnostics.dense_rank ?? '—' }}</dd>
            <dt>Lexical Rank</dt><dd>{{ diagnostics.lexical_rank ?? '—' }}</dd>
            <dt>Hybrid Rank</dt><dd>{{ diagnostics.hybrid_rank ?? '—' }}</dd>
            <dt>RRF Score</dt><dd>{{ score(diagnostics.rrf_score, 6) }}</dd>
        </dl>
        <details class="mt-2 border-t border-base-300 pt-1">
            <summary class="cursor-pointer py-1.5 font-semibold">Метаданные работы</summary>
            <dl v-if="diagnostics.metadata" class="space-y-1 [overflow-wrap:anywhere]">
                <div v-for="(label, key) in metadataLabels" :key="key">
                    <dt class="font-semibold">{{ label }}</dt>
                    <dd>{{ (Array.isArray(diagnostics.metadata[key]) ? diagnostics.metadata[key].join(', ') : diagnostics.metadata[key]) || '—' }}</dd>
                </div>
            </dl>
            <p v-else>Снимок метаданных недоступен.</p>
        </details>
    </div>
    </div>
</template>
