<template>
    <div>
        <draggable
            :list="drafts"
            item-key="id"
            @end="onEnd"
            class="space-y-2"
        >
            <template #item="{ element }">
                <div
                    class="relative p-2 bg-gray-800 rounded shadow cursor-pointer flex items-center"
                    :class="selectedDraftId === element.id ? 'border-l-4 border-purple-500' : ''"
                    @click="selectDraft(element.id)"
                >
                    <button
                        v-if="selectedDraftId === element.id"
                        class="absolute top-2 right-2 text-red-500"
                        @click.stop="confirmDeleteDraft(element.id)"
                    >
                        🗑
                    </button>

                    <img
                        v-if="element.media && element.media.length > 0"
                        :src="element.media[0].original_url + '?v=' + element.media[0].updated_at"
                        class="h-10 w-10 object-cover inline-block mr-2 rounded-full"
                    />
                    <div
                        v-else
                        class="h-10 w-10 inline-block mr-2 bg-gray-600 flex items-center justify-center text-white text-sm rounded-full"
                    >
                        N/A
                    </div>

                    <span class="text-white font-semibold">{{ element.title || 'Без названия' }}</span>
                </div>
            </template>
        </draggable>
    </div>
</template>

<script>
import draggable from 'vuedraggable'

export default {
    components: { draggable },
    props: {
        drafts: {
            type: Array,
            required: true
        },
        selectedDraftId: {
            type: Number,
            default: null
        }
    },
    setup(props, { emit }) {
        function onEnd() {
            const order = props.drafts.map(d => d.id)
            emit('reorder-drafts', order)
        }

        function selectDraft(id) {
            emit('selectDraft', id)
        }

        function confirmDeleteDraft(id) {
            emit('confirmDeleteDraft', id)
        }

        return { onEnd, selectDraft, confirmDeleteDraft }
    }
}
</script>
