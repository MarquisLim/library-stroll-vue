<script setup>
import { ArrowPathIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import AssistantArtworkCard from './AssistantArtworkCard.vue'
import AssistantSearchTrace from './AssistantSearchTrace.vue'

const { reply } = defineProps({ reply: { type: Object, required: true }, pending: Boolean, debug: Boolean })
defineEmits(['retry'])

const plural = new Intl.PluralRules('ru')
const candidates = computed(() => new Map(reply.diagnostics?.candidates.map(row => [row.artwork_id, row])))
const noun = { one: 'работа', few: 'работы', many: 'работ', other: 'работ' }
const summary = computed(() => {
    switch (reply.status) {
        case 'success': {
            const count = reply.results.length
            return `${plural.select(count) === 'one' ? 'Найдена' : 'Найдено'} ${count} ${noun[plural.select(count)]} по вашему запросу.`
        }
        case 'pending': return reply.message ?? 'Ищем подходящие работы…'
        case 'no_results':
        case 'empty': return 'Подходящих работ не найдено. Попробуйте другие слова или более общий запрос.'
        case 'search_fallback': return reply.sourceStatus === 'none'
            ? 'Этот ход завершился поиском. Карточки поисковой выдачи в истории не сохраняются.'
            : 'Не удалось подготовить ответ. Вот доступные результаты поиска.'
        case 'insufficient_evidence': return 'Недостаточно подтверждённых сведений для ответа.'
        case 'ok': return reply.fragments.length ? '' : 'Подтверждённых фрагментов сейчас нет.'
        case 'cancelled': return 'Ожидание отменено.'
        default: return reply.message
    }
})
</script>

<template>
    <div class="flex min-w-0 items-start gap-3 sm:gap-4">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary sm:h-10 sm:w-10" aria-hidden="true">
            <SparklesIcon class="h-4 w-4 sm:h-5 sm:w-5" />
        </span>
        <div class="min-w-0 flex-1 pt-1 sm:pt-2">
            <div v-if="summary" role="status" class="flex items-center gap-2 text-sm font-semibold leading-relaxed sm:text-base">
                <span v-if="reply.status === 'pending'" class="loading loading-spinner loading-xs shrink-0 text-primary" aria-hidden="true"></span>
                <p>{{ summary }}</p>
            </div>
            <p v-if="reply.sourceStatus === 'partial' || reply.sourceStatus === 'unavailable'" class="mb-2 text-xs font-semibold text-base-content/70">
                {{ reply.sourceStatus === 'partial' ? 'Часть прежних источников больше недоступна.' : 'Прежние источники больше нельзя подтвердить.' }}
            </p>
            <ul v-if="reply.fragments?.length" class="space-y-2.5 text-sm leading-relaxed sm:text-base" aria-label="Подтверждённые фрагменты">
                <li v-for="(fragment, index) in reply.fragments" :key="index" class="whitespace-pre-wrap [overflow-wrap:anywhere]">
                    {{ fragment.text }}
                    <Link :href="fragment.source.url" :aria-label="`Источник ${fragment.source.number}: ${fragment.source.title || 'Без названия'}`"
                          class="ml-1 inline-flex rounded-md bg-primary/10 px-1.5 py-0.5 align-baseline text-xs font-bold text-primary hover:bg-primary/20">
                        [{{ fragment.source.number }}]
                    </Link>
                </li>
            </ul>
            <ul v-if="reply.results?.length" class="mt-4 grid gap-3 border-t border-base-300/70 pt-4 sm:grid-cols-2" :class="{ 'xl:grid-cols-3': reply.results[0].number }" aria-label="Найденные работы">
                <li v-for="artwork in reply.results" :key="`${artwork.artwork_id}-${artwork.number ?? ''}`" class="min-w-0">
                    <AssistantArtworkCard :artwork="artwork" :diagnostics="debug ? candidates.get(artwork.artwork_id) : undefined" />
                </li>
            </ul>
            <button
                v-if="reply.status === 'error' || reply.status === 'cancelled'"
                type="button"
                class="btn btn-ghost mt-1 min-h-11 gap-2 rounded-xl px-2.5 text-sm text-primary"
                :disabled="pending"
                @click="$emit('retry')"
            >
                <ArrowPathIcon class="h-4 w-4" aria-hidden="true" />
                Повторить запрос
            </button>
            <AssistantSearchTrace v-if="debug && !['pending', 'error', 'cancelled'].includes(reply.status)" :trace="reply.diagnostics" />
        </div>
    </div>
</template>
