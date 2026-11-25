<script setup>
import { ArrowPathIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import { computed } from 'vue'
import AssistantArtworkCard from './AssistantArtworkCard.vue'

const { reply } = defineProps({ reply: { type: Object, required: true }, pending: Boolean })
defineEmits(['retry'])

const plural = new Intl.PluralRules('ru')
const noun = { one: 'работа', few: 'работы', many: 'работ', other: 'работ' }
const summary = computed(() => {
    switch (reply.status) {
        case 'success': {
            const count = reply.results.length
            return `${plural.select(count) === 'one' ? 'Найдена' : 'Найдено'} ${count} ${noun[plural.select(count)]} по вашему запросу.`
        }
        case 'pending': return 'Ищем подходящие работы…'
        case 'empty': return 'Подходящих работ не найдено. Попробуйте другие слова или более общий запрос.'
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
            <div role="status" class="flex items-center gap-2 text-sm font-semibold leading-relaxed sm:text-base">
                <span v-if="reply.status === 'pending'" class="loading loading-spinner loading-xs shrink-0 text-primary" aria-hidden="true"></span>
                <p>{{ summary }}</p>
            </div>
            <ul v-if="reply.status === 'success'" class="mt-3 grid gap-2.5 sm:grid-cols-2" aria-label="Найденные работы">
                <li v-for="artwork in reply.results" :key="artwork.artwork_id" class="min-w-0">
                    <AssistantArtworkCard :artwork="artwork" />
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
        </div>
    </div>
</template>
