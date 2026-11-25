<script setup>
import { ChatBubbleLeftIcon, PlusIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline'

defineProps({
    conversations: { type: Array, required: true },
    activeId: { type: String, default: null },
    loading: Boolean,
    hasMore: Boolean,
    error: { type: String, default: '' },
})
defineEmits(['new', 'open', 'delete', 'more', 'refresh', 'close'])

const date = new Intl.DateTimeFormat('ru', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })
function label(item) {
    const created = new Date(item.created_at)
    return Number.isNaN(created.valueOf()) ? 'Диалог' : date.format(created)
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-base-100 p-4">
        <div class="mb-4 flex items-center justify-between gap-2">
            <h2 class="truncate text-sm font-extrabold">История диалогов</h2>
            <button type="button" class="ui-icon-button h-11 w-11" aria-label="Закрыть историю" @click="$emit('close')">
                <XMarkIcon class="h-5 w-5" aria-hidden="true" />
            </button>
        </div>
        <button type="button" class="btn btn-primary min-h-11 justify-start gap-2 rounded-xl" @click="$emit('new')">
            <PlusIcon class="h-5 w-5" aria-hidden="true" /> Новый диалог
        </button>

        <div class="mt-4 min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-y-contain px-1 py-1">
            <p v-if="!conversations.length && !loading && !error" class="px-2 py-3 text-sm text-base-content/70">Сохранённых диалогов пока нет.</p>
            <ul v-if="conversations.length" class="space-y-1" aria-label="Сохранённые диалоги">
                <li v-for="item in conversations" :key="item.id" class="group flex items-center rounded-xl focus-within:bg-primary/5 hover:bg-primary/5" :class="item.id === activeId ? 'bg-primary/10' : ''">
                    <button type="button" class="flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-xl px-2 text-left text-xs font-semibold"
                            :class="item.id === activeId ? 'text-primary' : 'text-base-content/85'"
                            :aria-current="item.id === activeId ? 'true' : undefined" @click="$emit('open', item.id)">
                        <ChatBubbleLeftIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="truncate" :title="label(item)">{{ label(item) }}</span>
                    </button>
                    <button type="button" class="ui-icon-button min-h-11 min-w-11 hover:text-error" :aria-label="`Удалить диалог от ${label(item)}`" @click="$emit('delete', item)">
                        <TrashIcon class="h-4 w-4" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <p v-if="loading" role="status" class="px-2 py-2 text-xs text-base-content/70">Загружаем список…</p>
            <p v-if="error" role="alert" class="px-2 py-2 text-xs text-error">{{ error }}</p>
            <button v-if="error || hasMore" type="button" class="btn btn-ghost min-h-11 w-full rounded-xl text-xs text-primary" :disabled="loading" @click="$emit(error ? 'refresh' : 'more')">
                {{ error ? 'Обновить список' : 'Ещё диалоги' }}
            </button>
        </div>
    </div>
</template>
