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
    <div class="p-4 bg-base-200 rounded-lg shadow-sm">
        <h3 class="font-semibold text-lg mb-3">Мои черновики</h3>
        <draggable
            v-if="drafts.length"
            :list="drafts"
            item-key="id"
            @end="onEnd"
            class="space-y-2"
        >
            <template #item="{ element }">
                <div
                    class="relative flex items-center p-2 bg-base-100 hover:bg-base-300 rounded cursor-pointer transition"
                    :class="selectedDraftId === element.id ? 'border-l-4 border-primary' : ''"
                    @click="selectDraft(element.id)"
                >
                    <button
                        v-if="selectedDraftId === element.id"
                        class="absolute top-2 right-2 btn btn-xs btn-ghost"
                        @click.stop="confirmDeleteDraft(element.id)"
                    >
                        <TrashIcon class="w-4 h-4 text-error" />
                    </button>

                    <img
                        v-if="element.media?.length"
                        :src="element.thumb_url || `${element.media[0].original_url}?v=${element.media[0].updated_at}`"
                        class="h-10 w-10 object-cover rounded mr-3"
                    />

                    <div
                        v-else
                        class="h-10 w-10 bg-base-content/20 flex items-center justify-center rounded mr-3 text-base-content"
                    >
                        N/A
                    </div>

                    <span class="font-medium truncate">{{ element.title || 'Без названия' }}</span>
                </div>
            </template>
        </draggable>

        <div
            v-else
            class="py-10 text-center text-base-content/60 italic"
        >
            У вас пока нет черновиков.<br/>
            Нажмите кнопку «Создать» вверху или добавьте свой контент в выделенную зону, чтобы начать новый проект.
        </div>
    </div>
</template>
