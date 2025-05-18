<script setup>
import { storeToRefs } from 'pinia'
import { useArtworkActions } from '@/stores/useArtworkActions'

import CollectionSelector    from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'

const actions = useArtworkActions()
const {
    showSelector,  selectorPos,  selectedArt,
    showCreateModal, toast
} = storeToRefs(actions)
</script>

<template>
    <teleport to="body">
        <CollectionSelector
            v-if="showSelector"
            :position="selectorPos"
            :selected-collections="selectedArt.in_collections"
            @selected="actions.saveToCollections"
            @close   ="showSelector = false"
            @createCollection="actions.openCreateModal"
        />

        <CreateCollectionModal
            v-if="showCreateModal"
            @created="actions.addNewCollection"
            @close  ="actions.closeCreateModal"
        />

        <div
            v-if="toast"
            class="fixed bottom-4 left-1/2 -translate-x-1/2 bg-black bg-opacity-80 text-white px-4 py-2 rounded z-[9999]"
        >
            {{ toast }}
        </div>
    </teleport>
</template>
