<script setup>
import draggable from 'vuedraggable'
import { TrashIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    drafts: { type: Array, default: () => [] },
    selectedDraftId: Number,
})
const emit = defineEmits(['reorderDrafts', 'selectDraft', 'confirmDeleteDraft'])

function onEnd() {
    emit('reorderDrafts', props.drafts.map(d => d.id))
}
function selectDraft(id) {
    emit('selectDraft', id)
}
function confirmDeleteDraft(id) {
    emit('confirmDeleteDraft', id)
}
</script>
<template>
    <div class="ui-panel overflow-hidden p-4">
        <div class="mb-5 flex items-center justify-between"><h2 class="text-sm font-bold">Мои черновики</h2><span class="workspace-count">{{ drafts.length }}</span></div>
        <p v-if="drafts.length" class="ui-muted mb-4 text-xs">Выберите работу, чтобы продолжить</p>
        <draggable v-if="drafts.length" :list="drafts" item-key="id" handle=".draft-handle" @end="onEnd" class="space-y-2">
            <template #item="{ element }">
                <div :class="['group flex min-w-0 items-center gap-2 rounded-xl border p-2 transition-colors', selectedDraftId === element.id ? 'border-primary/30 bg-primary/5' : 'border-transparent hover:bg-base-200']">
                    <span class="draft-handle cursor-grab text-base-content/30" aria-hidden="true"><i class="mdi mdi-drag-vertical" /></span>
                    <button class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="selectDraft(element.id)" :aria-pressed="selectedDraftId === element.id">
                        <img v-if="element.media?.length" :src="element.thumb_url || element.media[0].original_url" alt="" class="h-11 w-11 shrink-0 rounded-lg bg-base-200 object-cover" />
                        <span v-else class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-xl text-primary"><i class="mdi mdi-image-outline" /></span>
                        <span class="min-w-0"><span class="block truncate text-sm font-semibold">{{ element.title || 'Без названия' }}</span><span class="ui-muted block text-xs">{{ element.type === 'video' ? 'Видео' : 'Изображение' }}</span></span>
                    </button>
                    <button class="btn btn-xs btn-ghost btn-square text-base-content/40 hover:text-error" aria-label="Удалить черновик" @click="confirmDeleteDraft(element.id)"><TrashIcon class="h-4 w-4" /></button>
                </div>
            </template>
        </draggable>
        <div v-else class="rounded-xl border border-dashed border-base-300 px-3 py-9 text-center"><i class="mdi mdi-lightbulb-on-outline text-3xl text-primary/60" /><p class="mt-3 text-sm font-semibold">Место для новой идеи</p><p class="ui-muted mt-2 text-xs leading-relaxed">Загрузите файл в редактор или нажмите «Новая работа».</p></div>
    </div>
</template>
