<template>
    <div>
        <draggable
            :list="localDrafts"
            item-key="id"
            @end="onEnd"
            class="space-y-2"
        >
            <template #item="{ element }">
                <div
                    class="relative p-2 bg-base-100 rounded shadow cursor-pointer"
                    :class="selectedDraftId === element.id ? 'border-l-4 border-primary' : ''"
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
                        v-if="element.media && element.media.length>0"
                        :src="element.media[0].original_url"
                        class="h-10 w-10 object-cover inline-block mr-2 rounded-full"
                    />
                    <div
                        v-else
                        class="h-10 w-10 inline-block mr-2 bg-gray-700 flex items-center justify-center text-white text-sm rounded-full"
                    >
                        N/A
                    </div>

                    <span>{{ element.title || 'Создать искусство' }}</span>
                </div>
            </template>
        </draggable>
    </div>
</template>

<script>
import { ref, watch } from 'vue'
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
        const localDrafts = ref([...props.drafts])

        watch(() => props.drafts, (newVal) => {
            localDrafts.value = [...newVal]
        })

        function onEnd() {
            const order = localDrafts.value.map(d => d.id)
            emit('reorder-drafts', order)
        }

        function selectDraft(id) {
            emit('selectDraft', id)
        }

        function confirmDeleteDraft(id) {
            emit('confirmDeleteDraft', id)
        }

        return { localDrafts, onEnd, selectDraft, confirmDeleteDraft }
    }
}
</script>
