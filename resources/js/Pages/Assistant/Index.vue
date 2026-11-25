<script setup>
import { MagnifyingGlassIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AssistantComposer from '@/Components/Assistant/AssistantComposer.vue'
import AssistantMessage from '@/Components/Assistant/AssistantMessage.vue'
import AssistantWelcome from '@/Components/Assistant/AssistantWelcome.vue'
import { useAssistantSearch } from '@/composables/useAssistantSearch.js'

const { draft, turns, pending, canSend, send, cancel, retry, reset } = useAssistantSearch()
const composer = ref(null)
const conversation = ref(null)
const viewportHeight = ref(null)
const viewportStyle = computed(() => viewportHeight.value ? { '--workspace-viewport-height': `${viewportHeight.value}px` } : undefined)
let viewport

function fitViewport() {
    viewportHeight.value = viewport?.scale === 1 ? viewport.height : null
}

onMounted(() => {
    viewport = window.visualViewport
    viewport?.addEventListener('resize', fitViewport)
    fitViewport()
})
onUnmounted(() => viewport?.removeEventListener('resize', fitViewport))

function chooseExample(query) {
    draft.value = query
    composer.value?.focus()
}

function newConversation() {
    reset()
    composer.value?.focus()
}

function cancelWait() {
    cancel()
    composer.value?.focus()
}

watch(() => turns.value.length, async (length, previousLength) => {
    if (length > previousLength) {
        await nextTick()
        const scroller = conversation.value
        if (!scroller) return
        scroller.scrollTop = scroller.scrollHeight
    }
})
</script>

<template>
    <AppLayout title="Library Stroll AI" workspace :style="viewportStyle">
        <div class="flex h-full min-h-0 min-w-0 flex-col bg-base-200">
            <header class="shrink-0 border-b border-base-300/75 bg-base-100">
                <div class="mx-auto flex w-full max-w-[66rem] items-center justify-between gap-3 px-4 py-3 sm:px-7 sm:py-4">
                    <div class="min-w-0">
                        <h1 class="text-lg font-extrabold leading-tight tracking-tight sm:text-xl">Library Stroll <span class="text-primary">AI</span></h1>
                        <p class="mt-1 flex items-center gap-1.5 text-xs font-medium text-base-content/70 sm:text-sm">
                            <MagnifyingGlassIcon class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            Поиск изображений
                        </p>
                    </div>
                    <button type="button" class="btn btn-ghost min-h-11 shrink-0 gap-1.5 rounded-xl border-base-300 bg-base-100 px-2.5 text-xs sm:gap-2 sm:px-3.5 sm:text-sm" @click="newConversation">
                        <PlusIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                        Новый диалог
                    </button>
                </div>
            </header>

            <div ref="conversation" class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain" role="region" aria-label="Диалог с ассистентом" tabindex="0">
                <div class="mx-auto flex min-h-full w-full max-w-[66rem] flex-col px-4 sm:px-7">
                    <AssistantWelcome v-if="!turns.length" @choose="chooseExample" />
                    <ol v-else class="space-y-7 py-5 sm:space-y-9 sm:py-8">
                        <li v-for="turn in turns" :key="turn.id" class="min-w-0">
                            <p class="mb-4 ml-auto w-fit max-w-[92%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-primary/10 px-4 py-3 text-sm font-semibold leading-relaxed [overflow-wrap:anywhere] sm:max-w-[80%] sm:text-base">
                                <span class="sr-only">Вы: </span>{{ turn.query }}
                            </p>
                            <AssistantMessage :reply="turn.reply" :pending="pending" @retry="retry(turn)" />
                        </li>
                    </ol>
                </div>
            </div>

            <div class="shrink-0 bg-base-200">
                <div class="mx-auto w-full max-w-[66rem] px-4 pb-2 pt-2 sm:px-7 sm:pb-4">
                    <AssistantComposer ref="composer" v-model="draft" :pending="pending" :can-send="canSend" @send="send" @cancel="cancelWait" />
                    <p class="mt-2 text-center text-[11px] leading-snug text-base-content/65 sm:text-xs">Каждый запрос обрабатывается отдельно.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
