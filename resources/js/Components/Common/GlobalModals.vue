<script setup>
import { storeToRefs } from 'pinia'
import { useArtworkActions } from '@/stores/useArtworkActions'

import CollectionSelector    from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'

const actions = useArtworkActions()
const {
    showSelector,
    selectorPos,
    selectedArt,
    showCreateModal,
    toast
} = storeToRefs(actions)
</script>

<template>
    <teleport to="body">
        <CollectionSelector
            v-if="showSelector"
            :position="selectorPos"
            :selected-collections="selectedArt.in_collections"
            @selected="actions.saveToCollections"
            @close="showSelector = false"
            @createCollection="actions.openCreateModal"
        />

        <CreateCollectionModal
            v-if="showCreateModal"
            @created="actions.addNewCollection"
            @close="actions.closeCreateModal"
        />

        <div
            v-if="toast.visible"
            :class="[
    'fixed top-16 right-4 z-[9999] px-4 py-3 rounded shadow-lg flex items-center gap-2',
    toast.type === 'success'
      ? 'bg-green-500 text-white'
    : toast.type === 'error'
      ? 'bg-red-500 text-white'
    : toast.type === 'info'
      ? 'bg-yellow-500 text-white'
    : ''
  ]"
            role="alert"
        >
            <svg
                v-if="toast.type === 'success'"
                xmlns="http://www.w3.org/2000/svg"
                class="h-6 w-6 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                />
            </svg>
            <svg
                v-else-if="toast.type === 'error'"
                xmlns="http://www.w3.org/2000/svg"
                class="h-6 w-6 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"
                />
            </svg>
            <svg
                v-else-if="toast.type === 'info'"
                xmlns="http://www.w3.org/2000/svg"
                class="h-6 w-6 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"
                />
            </svg>
            <span class="truncate">{{ toast.message }}</span>
        </div>
    </teleport>
</template>
