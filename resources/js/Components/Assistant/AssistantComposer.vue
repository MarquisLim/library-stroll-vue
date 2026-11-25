<script setup>
import { ArrowUpIcon, StopIcon } from '@heroicons/vue/24/solid'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { assistantQueryLimit } from '../../composables/useAssistantSearch.js'

const draft = defineModel({ type: String, required: true })
defineProps({ pending: Boolean, canSend: Boolean })
const emit = defineEmits(['send', 'cancel'])
const input = ref(null)
const length = computed(() => Array.from(draft.value).length)
let resizeObserver
let measuredWidth

function resize() {
    input.value.style.height = 'auto'
    input.value.style.height = `${input.value.scrollHeight}px`
}

function update(event) {
    if (event.isComposing) return
    const value = event.target.value
    const limited = Array.from(value).slice(0, assistantQueryLimit).join('')
    if (limited !== value) event.target.value = limited
    draft.value = limited
}

function handleEnter(event) {
    if (event.shiftKey || event.isComposing || event.keyCode === 229) return
    event.preventDefault()
    if (!event.repeat) emit('send')
}

defineExpose({ focus: () => input.value?.focus({ preventScroll: true }) })
onMounted(() => {
    resize()
    resizeObserver = new ResizeObserver(([{ contentRect }]) => {
        if (contentRect.width === measuredWidth) return
        measuredWidth = contentRect.width
        resize()
    })
    resizeObserver.observe(input.value)
})
onUnmounted(() => resizeObserver?.disconnect())
watch(draft, resize, { flush: 'post' })
</script>

<template>
    <form class="rounded-2xl border border-base-300 bg-base-100 p-2 shadow-[var(--ui-shadow)] focus-within:border-primary/55 sm:p-3" @submit.prevent="emit('send')">
        <label for="assistant-query" class="sr-only">Поисковый запрос</label>
        <textarea
            id="assistant-query"
            ref="input"
            :value="draft"
            rows="1"
            maxlength="1000"
            enterkeyhint="search"
            class="block max-h-32 min-h-[4.25rem] w-full resize-none overflow-y-auto rounded-xl border-0 bg-transparent px-2.5 py-2 text-base leading-relaxed text-base-content placeholder:text-base-content/60 focus:outline-none focus:ring-0 sm:min-h-11"
            placeholder="Найдите изображения, авторов или интересующие темы…"
            aria-describedby="assistant-query-hint"
            @input="update"
            @compositionend="update"
            @keydown.enter="handleEnter"
        ></textarea>
        <div class="flex items-center justify-between gap-2 px-1 pt-1 sm:px-1.5">
            <span id="assistant-query-hint" class="text-xs tabular-nums text-base-content/65">
                <span>{{ length }} / {{ assistantQueryLimit }}</span>
                <span class="sr-only">символов.</span>
                <span class="ml-3 hidden sm:inline">Enter — отправить · Shift+Enter — новая строка</span>
            </span>
            <button
                v-if="pending"
                type="button"
                class="btn btn-ghost min-h-11 gap-2 rounded-xl px-2.5 text-sm text-primary"
                @click="emit('cancel')"
            >
                <StopIcon class="h-4 w-4" aria-hidden="true" />
                Отменить ожидание
            </button>
            <button v-else type="submit" class="btn btn-primary min-h-11 w-11 rounded-xl p-0" :disabled="!canSend" aria-label="Отправить запрос">
                <ArrowUpIcon class="h-5 w-5" aria-hidden="true" />
            </button>
        </div>
    </form>
</template>
