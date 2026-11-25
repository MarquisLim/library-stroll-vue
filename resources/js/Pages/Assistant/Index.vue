<script setup>
import { ChatBubbleLeftRightIcon, ClockIcon, MagnifyingGlassIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AssistantComposer from '@/Components/Assistant/AssistantComposer.vue'
import AssistantMessage from '@/Components/Assistant/AssistantMessage.vue'
import AssistantSidebar from '@/Components/Assistant/AssistantSidebar.vue'
import AssistantWelcome from '@/Components/Assistant/AssistantWelcome.vue'
import { useAssistantConversation } from '@/composables/useAssistantConversation.js'
import { useAssistantSearch } from '@/composables/useAssistantSearch.js'

const props = defineProps({ historyEnabled: Boolean, dialogueEnabled: Boolean, contextEnabled: Boolean, dialogueQueryLimit: { type: Number, default: 500 } })
const local = reactive(useAssistantSearch())
const history = reactive(useAssistantConversation(undefined, props.dialogueQueryLimit))
const chat = computed(() => props.dialogueEnabled ? history : local)
const readOnly = computed(() => !!history.activeId && !props.dialogueEnabled)
const turns = computed(() => readOnly.value ? history.turns : chat.value.turns)
const draft = computed({ get: () => chat.value.draft, set: value => { chat.value.draft = value } })
const composer = ref(null)
const conversation = ref(null)
const drawer = ref(null)
const confirmDelete = ref(null)
const deleteTarget = ref(null)
const historyToggle = ref(null)
const sidebarOpen = ref(true)
const viewportHeight = ref(null)
const viewportStyle = computed(() => viewportHeight.value ? { '--workspace-viewport-height': `${viewportHeight.value}px` } : undefined)
let viewport
let desktop

function fitViewport() {
    viewportHeight.value = viewport?.scale === 1 ? viewport.height : null
}

onMounted(() => {
    viewport = window.visualViewport
    viewport?.addEventListener('resize', fitViewport)
    fitViewport()
    desktop = window.matchMedia('(min-width: 1024px)')
    desktop.addEventListener('change', closeDrawerOnDesktop)
    if (props.historyEnabled) void history.loadList()
})
onUnmounted(() => {
    viewport?.removeEventListener('resize', fitViewport)
    desktop?.removeEventListener('change', closeDrawerOnDesktop)
})

function closeDrawerOnDesktop(event) {
    if (event.matches) drawer.value?.close()
}

function toggleHistory() {
    if (desktop?.matches) sidebarOpen.value = !sidebarOpen.value
    else {
        historyToggle.value?.focus({ preventScroll: true })
        drawer.value?.showModal()
    }
}

async function closeSidebar() {
    if (drawer.value?.open) drawer.value.close()
    else sidebarOpen.value = false
    await nextTick()
    historyToggle.value?.focus()
}

function chooseExample(query) {
    draft.value = query
    composer.value?.focus()
}

async function newConversation() {
    drawer.value?.close()
    history.open()
    local.reset()
    await nextTick()
    composer.value?.focus()
}

function cancelWait() {
    chat.value.cancel()
    composer.value?.focus()
}

async function scrollBottom() {
    await nextTick()
    const scroller = conversation.value
    if (scroller) scroller.scrollTop = scroller.scrollHeight
}

async function revealLatestTurn() {
    await nextTick()
    const scroller = conversation.value
    const latest = scroller?.querySelector('ol > li:last-child')
    if (latest) {
        scroller.scrollTop = Math.max(0, scroller.scrollTop + latest.getBoundingClientRect().top - scroller.getBoundingClientRect().top - 16)
    }
}

function send() {
    if (!chat.value.canSend) return
    void chat.value.send()
    void revealLatestTurn()
}

async function openConversation(id) {
    drawer.value?.close()
    local.reset()
    const opened = history.open(id)
    conversation.value?.focus({ preventScroll: true })
    if (await opened && history.activeId === id) await scrollBottom()
}

async function refreshHistory() {
    const id = history.activeId
    if (await history.refresh() && id && history.activeId === id) await scrollBottom()
}

async function loadOlder() {
    const scroller = conversation.value
    const height = scroller.scrollHeight
    const top = scroller.scrollTop
    const id = history.activeId
    if (!await history.loadHistory(true) || history.activeId !== id) return
    await nextTick()
    scroller.scrollTop = top + (scroller.scrollHeight - height)
}

function requestDelete(item) {
    history.deleteError = ''
    deleteTarget.value = item
    confirmDelete.value?.showModal()
}

async function deleteConversation() {
    if (deleteTarget.value && await history.remove(deleteTarget.value.id)) confirmDelete.value?.close()
}

const sidebarProps = computed(() => ({
    conversations: history.conversations, activeId: history.activeId,
    loading: !!history.listRequest, hasMore: history.listMore, error: history.listError,
}))
</script>

<template>
    <AppLayout title="Library Stroll AI" workspace :style="viewportStyle">
        <div class="flex h-full min-h-0 min-w-0 bg-base-200">
            <aside v-if="historyEnabled && sidebarOpen" class="hidden w-[17rem] shrink-0 border-r border-base-300 lg:block">
                <AssistantSidebar v-bind="sidebarProps" @open="openConversation" @new="newConversation" @delete="requestDelete"
                                  @close="closeSidebar" @refresh="history.loadList()" @more="history.loadList(true)" />
            </aside>
            <dialog v-if="historyEnabled" ref="drawer" aria-label="История диалогов"
                    class="fixed inset-y-0 left-0 right-auto m-0 h-full max-h-none w-[min(18rem,calc(100%_-_2rem))] max-w-none border-r border-base-300 bg-base-100 pb-safe-b pt-safe-t text-base-content backdrop:bg-base-content/35"
                    @click.self="drawer.close()">
                <AssistantSidebar v-bind="sidebarProps" @open="openConversation" @new="newConversation" @delete="requestDelete"
                                  @close="closeSidebar" @refresh="history.loadList()" @more="history.loadList(true)" />
            </dialog>
            <section class="flex min-h-0 min-w-0 flex-1 flex-col">
                <header class="shrink-0 border-b border-base-300/75 bg-base-100">
                    <div class="mx-auto flex w-full max-w-[66rem] items-center justify-between gap-3 px-4 py-3 sm:px-7 sm:py-4">
                        <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                            <button v-if="historyEnabled" ref="historyToggle" type="button" class="btn btn-ghost min-h-11 w-11 shrink-0 rounded-xl px-0 text-primary"
                                    aria-label="Открыть или свернуть историю" @click="toggleHistory">
                                <ClockIcon class="h-5 w-5" aria-hidden="true" />
                            </button>
                            <div class="min-w-0">
                                <h1 class="text-lg font-extrabold leading-tight tracking-tight sm:text-xl">Library Stroll <span class="text-primary">AI</span></h1>
                                <p class="mt-1 flex items-center gap-1.5 text-xs font-medium text-base-content/70 sm:text-sm">
                                    <component :is="dialogueEnabled ? ChatBubbleLeftRightIcon : MagnifyingGlassIcon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ readOnly ? 'Сохранённый диалог' : dialogueEnabled ? 'Диалог с источниками' : 'Поиск изображений' }}
                                </p>
                            </div>
                        </div>
                        <button type="button" class="btn btn-ghost min-h-11 shrink-0 gap-1.5 rounded-xl border-base-300 bg-base-100 px-2.5 text-xs sm:gap-2 sm:px-3.5 sm:text-sm" @click="newConversation">
                            <PlusIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                            Новый диалог
                        </button>
                    </div>
                </header>

                <div ref="conversation" class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain" role="region" aria-label="Диалог с ассистентом" tabindex="0">
                    <div class="mx-auto flex min-h-full w-full max-w-[66rem] flex-col px-4 sm:px-7">
                        <div v-if="history.activeId && (history.historyRequest || history.historyError || history.historyMore)" class="pt-4 text-center text-sm text-base-content/70">
                            <p v-if="history.historyRequest" role="status">Загружаем историю…</p>
                            <template v-else-if="history.historyError">
                                <p role="alert" class="text-error">{{ history.historyError }}</p>
                                <button type="button" class="btn btn-ghost min-h-11 text-primary" @click="refreshHistory">Обновить историю</button>
                            </template>
                            <button v-else type="button" class="btn btn-ghost min-h-11 text-primary" :disabled="history.pending" @click="loadOlder">Загрузить ранние сообщения</button>
                        </div>
                        <AssistantWelcome v-if="!turns.length && !history.historyRequest && !history.historyError && !readOnly" @choose="chooseExample" />
                        <p v-else-if="readOnly && !turns.length && !history.historyRequest && !history.historyError" class="m-auto text-sm text-base-content/70">В диалоге пока нет сообщений.</p>
                        <ol v-if="turns.length" class="space-y-7 py-5 sm:space-y-9 sm:py-8">
                            <li v-for="turn in turns" :key="turn.id" class="min-w-0">
                                <p class="mb-4 ml-auto w-fit max-w-[92%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-primary/10 px-4 py-3 text-sm font-semibold leading-relaxed [overflow-wrap:anywhere] sm:max-w-[80%] sm:text-base">
                                    <span class="sr-only">Вы: </span>{{ turn.query }}
                                </p>
                                <AssistantMessage :reply="turn.reply" :pending="chat.pending" @retry="local.retry(turn)" />
                            </li>
                        </ol>
                    </div>
                </div>

                <div class="shrink-0 bg-base-200">
                    <div class="mx-auto w-full max-w-[66rem] px-4 pb-2 pt-2 sm:px-7 sm:pb-4">
                        <div v-if="dialogueEnabled && history.notice" class="mb-2 rounded-xl border border-base-300 bg-base-100 px-3 py-2 text-xs sm:text-sm">
                            <p role="status">{{ history.notice.saved ? 'В истории найден похожий запрос. Проверьте его перед повторной отправкой.' : history.notice.message }}</p>
                            <div v-if="!history.notice.saved" class="mt-1 flex flex-wrap gap-x-2">
                                <button type="button" class="btn btn-ghost min-h-10 px-2 text-xs text-primary" :disabled="!!history.historyRequest || !!history.listRequest" @click="refreshHistory">{{ history.activeId ? 'Обновить историю' : 'Обновить список' }}</button>
                                <button v-if="!history.notice.refreshRequired" type="button" class="btn btn-ghost min-h-10 px-2 text-xs text-primary" @click="history.restoreDraft(); composer?.focus()">Вернуть запрос в поле</button>
                            </div>
                        </div>
                        <AssistantComposer v-if="!readOnly" ref="composer" v-model="draft" :limit="dialogueEnabled ? dialogueQueryLimit : 500" :pending="chat.pending" :can-send="chat.canSend" @send="send" @cancel="cancelWait" />
                        <p v-if="!dialogueEnabled || !contextEnabled" class="mt-2 text-center text-[11px] leading-snug text-base-content/65 sm:text-xs">
                            {{ readOnly ? 'Новые сообщения в этом режиме доступны через независимый поиск.' : dialogueEnabled ? 'Контекстное уточнение отключено.' : 'Каждый запрос обрабатывается отдельно.' }}
                        </p>
                    </div>
                </div>
            </section>
            <dialog v-if="historyEnabled" ref="confirmDelete" aria-labelledby="assistant-delete-title"
                    class="m-auto w-[min(24rem,calc(100%_-_2rem))] rounded-2xl border border-base-300 bg-base-100 p-5 text-base-content backdrop:bg-base-content/35"
                    @close="deleteTarget = null" @click.self="confirmDelete.close()">
                <h2 id="assistant-delete-title" class="text-lg font-extrabold">Удалить диалог?</h2>
                <p class="mt-2 text-sm text-base-content/75">Все сообщения этого диалога будут удалены из вашей истории.</p>
                <p v-if="history.deleteError" role="alert" class="mt-2 text-sm text-error">{{ history.deleteError }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost min-h-11" autofocus @click="confirmDelete.close()">Отмена</button>
                    <button type="button" class="btn btn-error min-h-11" :disabled="!!history.deletion" @click="deleteConversation">{{ history.deletion ? 'Удаляем…' : 'Удалить' }}</button>
                </div>
            </dialog>
        </div>
    </AppLayout>
</template>
