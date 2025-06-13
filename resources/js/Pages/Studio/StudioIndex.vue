<script setup>
import { ref, onMounted, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import debounce from 'lodash.debounce'

import AppLayout from '@/Layouts/AppLayout.vue'
import StudioDraftList from '@/Components/Studio/StudioDraftList.vue'
import StudioDraftForm from '@/Components/Studio/StudioDraftForm.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import { useArtworkActions } from '@/stores/useArtworkActions'

// initial data
const { drafts: initialDrafts, collections: initialCollections } = usePage().props
const drafts = ref([...initialDrafts])
const collections = ref([...initialCollections])
const tagSuggestions = ref([])
const collectionSuggestions = ref([])

// artwork store
const artworkStore = useArtworkActions()

// constants
const MAX_SIZE = 20 * 1024 * 1024 // 20MB

// UI state
const showList = ref(true)
const showCollectionModal = ref(false)
const showCreateCollectionModal = ref(false)
const showCreateModal = ref(false)
const confirmingDraftDeletion = ref(false)

// draft form state
const selectedDraftId = ref(null)
const title = ref('')
const description = ref('')
const is_adult = ref(false)
const has_ai = ref(false)
const is_private = ref(false)
const allow_download = ref(true)
const allow_comments = ref(true)
const tags = ref([])
const selectedCollections = ref([])
const previewUrl = ref(null)
const fileType = ref(null)

// file upload state
const tempFile = ref(null)
const isUploading = ref(false)
const uploadProgress = ref(0)

// auto-save flags
const isDirty = ref(false)
const isSaving = ref(false)

// debounced auto-save
const autoSave = debounce(async () => {
    if (!selectedDraftId.value) return
    isSaving.value = true
    artworkStore.notify('Сохраняем...', 'info')
    try {
        const res = await axios.post(`/studio/update-draft/${selectedDraftId.value}`, {
            title: title.value,
            description: description.value,
            is_adult: is_adult.value,
            has_ai: has_ai.value,
            is_private: is_private.value,
            allow_download: allow_download.value,
            allow_comments: allow_comments.value,
            tags: tags.value,
            collections: selectedCollections.value,
        })
        // update local draft
        const art = res.data.artwork
        updateDraftInLocal(art)
        isDirty.value = false
        artworkStore.notify('Сохранено')
    } catch (err) {
        artworkStore.notify('Ошибка сохранения', 'error')
    } finally {
        isSaving.value = false
    }
}, 2000)

// watch fields for changes
watch(
    [title, description, is_adult, has_ai, is_private, allow_download, allow_comments, tags, selectedCollections],
    () => {
        if (selectedDraftId.value) {
            isDirty.value = true
            autoSave()
        }
    }
)

// helper to update local drafts array
function updateDraftInLocal(artwork) {
    const idx = drafts.value.findIndex(d => d.id === artwork.id)
    if (idx > -1) drafts.value.splice(idx, 1, artwork)
    else drafts.value.push(artwork)
}

// select a draft
function selectDraft(draftId) {
    const draft = drafts.value.find(d => d.id === draftId)
    if (draft) {
        selectedDraftId.value = draftId
        title.value = draft.title || ''
        description.value = draft.description || ''
        is_adult.value = !!draft.is_adult
        has_ai.value = !!draft.has_ai
        is_private.value = !!draft.is_private
        allow_download.value = !!draft.allow_download
        allow_comments.value = !!draft.allow_comments
        tags.value = draft.tags?.map(t => t.name) || []
        selectedCollections.value = draft.collections?.map(c => c.id) || []
        previewUrl.value = draft.media?.[0]?.original_url || null
        fileType.value = determineFileType(previewUrl.value)
        isDirty.value = false
    } else {
        resetFields()
    }
}

// determine file type
function determineFileType(url) {
    if (!url) return null
    const ext = url.split('.').pop().toLowerCase()
    if (['jpg','jpeg','png','gif'].includes(ext)) return 'image'
    if (['mp4','mov','avi','webm'].includes(ext)) return 'video'
    return 'other'
}

// create new draft via file drop
function onDropFileCreate(e) {
    const file = e.dataTransfer.files[0]
    if (validateFile(file)) tempFile.value = file
}
const createFileInput = ref(null)
function browseFileCreate() { createFileInput.value.click() }
function onFileChangeCreate(e) {
    const file = e.target.files[0]
    if (validateFile(file)) tempFile.value = file
}
async function createArtFromFile() {
    if (!tempFile.value) return
    isUploading.value = true
    uploadProgress.value = 0
    const formData = new FormData()
    formData.append('file', tempFile.value)
    try {
        const res = await axios.post('/studio/upload-file', formData, {
            onUploadProgress: ev => (uploadProgress.value = Math.round(ev.loaded / ev.total * 100))
        })
        artworkStore.notify(res.data.message)
        updateDraftInLocal(res.data.artwork)
        selectDraft(res.data.artwork.id)
        showCreateModal.value = false
        tempFile.value = null
    } catch (err) {
        artworkStore.notify('Ошибка загрузки файла', 'error')
    } finally {
        isUploading.value = false
        uploadProgress.value = 0
    }
}

// delete draft
async function destroyDraft() {
    confirmingDraftDeletion.value = false
    try {
        const res = await axios.delete(`/studio/draft/${selectedDraftId.value}`)
        artworkStore.notify(res.data.message)
        drafts.value = drafts.value.filter(d => d.id !== selectedDraftId.value)
        selectedDraftId.value = null
        resetFields()
    } catch (err) {
        artworkStore.notify('Ошибка удаления', 'error')
    }
}

// publish draft
async function publishDraft() {
    try {
        const res = await axios.post(`/studio/publish/${selectedDraftId.value}`)
        artworkStore.notify(res.data.message)
        drafts.value = drafts.value.filter(d => d.id !== selectedDraftId.value)
        selectedDraftId.value = null
        resetFields()
    } catch (err) {
        artworkStore.notify(err.response?.data.error || 'Ошибка публикации', 'error')
    }
}

// upload file to existing draft
async function uploadFile(file) {
    if (!validateFile(file)) return
    isUploading.value = true
    uploadProgress.value = 0
    const formData = new FormData()
    formData.append('file', file)
    if (selectedDraftId.value) formData.append('draftId', selectedDraftId.value)
    try {
        const res = await axios.post('/studio/upload-file', formData, {
            onUploadProgress: ev => (uploadProgress.value = Math.round(ev.loaded / ev.total * 100))
        })
        artworkStore.notify(res.data.message)
        updateDraftInLocal(res.data.artwork)
        selectDraft(res.data.artwork.id)
    } catch (err) {
        artworkStore.notify('Ошибка загрузки файла', 'error')
    } finally {
        isUploading.value = false
        uploadProgress.value = 0
    }
}

// reorder drafts
async function reorderDrafts(newOrder) {
    try {
        const res = await axios.post('/studio/reorder-drafts', { draft_order: newOrder })
        artworkStore.notify(res.data.message)
    } catch (err) {
        artworkStore.notify('Ошибка обновления порядка', 'error')
    }
}

// tag/collection handlers
function addTag(tagName) {
    if (!tags.value.includes(tagName)) tags.value.push(tagName)
}
function searchTags(query) {
    axios
        .get(`/studio/search-tags?query=${encodeURIComponent(query)}`)
        .then(res => {
            tagSuggestions.value = res.data.tags.map(t => t.name)
        })
        .catch(err => console.error(err))
}

function searchCollections(query) {
    axios
        .get(`/studio/search-collections?query=${encodeURIComponent(query)}`)
        .then(res => {
            collectionSuggestions.value = res.data.collections
        })
        .catch(err => console.error(err))
}

// collection created
function collectionCreated(col) {
    collections.value.push(col)
    artworkStore.notify('Коллекция создана')
    showCreateCollectionModal.value = false
}

// file validation
function validateFile(file) {
    if (!file) return false
    if (file.size > MAX_SIZE) {
        artworkStore.notify('Файл превышает 20 МБ', 'error')
        return false
    }
    return true
}

// reset fields
function resetFields() {
    selectedDraftId.value = null
    title.value = ''
    description.value = ''
    is_adult.value = false
    has_ai.value = false
    is_private.value = false
    allow_download.value = true
    allow_comments.value = true
    tags.value = []
    selectedCollections.value = []
    previewUrl.value = null
    fileType.value = null
    isDirty.value = false
}

const errorMessages = ref([])

// initialize store collections
onMounted(() => {
    artworkStore.setCollections(collections.value)
})
</script>
<template>
    <AppLayout title="Студия">
        <div class="p-4 bg-base-100 text-base-content min-h-screen">
            <!-- mobile toggle -->
            <button class="btn btn-ghost mb-4 md:hidden flex items-center gap-2" @click="showList = !showList">
                <component :is="showList ? ChevronLeftIcon : ChevronRightIcon" class="w-5 h-5" />
                <span>{{ showList ? 'Скрыть список' : 'Показать список' }}</span>
            </button>

            <div class="flex flex-col md:flex-row gap-4">
                <!-- draft list -->
                <aside v-show="showList" class="w-full md:w-1/4 flex flex-col">
                    <button @click="showCreateModal = true" class="btn btn-primary w-full mb-4">
                        Создать искусство
                    </button>
                    <StudioDraftList
                        :drafts="drafts"
                        :selected-draft-id="selectedDraftId"
                        @selectDraft="selectDraft"
                        @confirmDeleteDraft="id => { selectedDraftId = id; confirmingDraftDeletion = true }"
                        @reorderDrafts="reorderDrafts"
                    />
                </aside>

                <!-- edit form -->
                <main class="w-full md:w-3/4 bg-base-200 p-4 rounded-lg shadow flex-1 relative">
                    <StudioDraftForm
                        :selectedDraftId="selectedDraftId"
                        :validateFile="validateFile"
                        :previewUrl="previewUrl"
                        :fileType="fileType"
                        :title="title"
                        :description="description"
                        :is_adult="is_adult"
                        :has_ai="has_ai"
                        :is_private="is_private"
                        :allow_download="allow_download"
                        :allow_comments="allow_comments"
                        :tags="tags"
                        :tagSuggestions="tagSuggestions"
                        :collectionSuggestions="collectionSuggestions"
                        :collections="collections"
                        :isUploading="isUploading"
                        :uploadProgress="uploadProgress"
                        :errorMessages="errorMessages"
                        :isDirty="isDirty"
                        :isSaving="isSaving"
                        @uploadFile="uploadFile"
                        @publish="publishDraft"
                        @destroyDraft="destroyDraft"
                        @update:title="val => title = val"
                        @update:description="val => description = val"
                        @update:is_adult="val => is_adult = val"
                        @update:has_ai="val => has_ai = val"
                        @update:is_private="val => is_private = val"
                        @update:allow_download="val => allow_download = val"
                        @update:allow_comments="val => allow_comments = val"
                        @tagsUpdated="val => tags = val"
                        @searchTags="searchTags"
                        @searchCollections="searchCollections"
                        @update:showCollectionModal="showCollectionModal = $event"
                    />

                </main>
            </div>

            <!-- create modal -->
            <div v-if="showCreateModal" class="modal modal-open">
                <div class="modal-box bg-base-200 text-base-content">
                    <h3 class="font-bold text-lg">Создать искусство</h3>
                    <p class="my-2 text-base-content/70">Загрузите файл для нового черновика:</p>
                    <div class="border-2 border-dashed border-base-content/50 p-4 rounded text-center cursor-pointer relative"
                         @drop.prevent="onDropFileCreate" @dragover.prevent @click="browseFileCreate">
                        <input type="file" ref="createFileInput" class="hidden" @change="onFileChangeCreate" />
                        <p v-if="!tempFile">Перетащите файл или нажмите</p>
                        <p v-else>Выбран файл: {{ tempFile.name }}</p>
                        <p v-if="errorMessages.length" class="text-error mt-2">{{ errorMessages[0] }}</p>
                        <progress v-if="isUploading" class="progress w-full progress-primary mt-4"
                                  :value="uploadProgress" max="100" />
                    </div>
                    <div class="modal-action">
                        <button class="btn" @click="showCreateModal = false">Отмена</button>
                        <button class="btn btn-primary" @click="createArtFromFile" :disabled="!tempFile">
                            Создать
                        </button>
                    </div>
                </div>
            </div>

            <!-- delete confirm -->
            <div v-if="confirmingDraftDeletion" class="modal modal-open">
                <div class="modal-box bg-base-200 text-base-content">
                    <h3 class="font-bold text-lg">Удалить черновик?</h3>
                    <p class="text-base-content/70">Вы уверены?</p>
                    <div class="modal-action">
                        <button class="btn" @click="cancelDelete">Отмена</button>
                        <button class="btn btn-error" @click="destroyDraft">Удалить</button>
                    </div>
                </div>
            </div>

            <!-- teleported modals -->
            <Teleport to="body">
                <CollectionSelector
                    v-if="showCollectionModal"
                    :position="{ top: '30%', left: '50%' }"
                    :collections="collections"
                    :selected-collections="selectedCollections"
                    @selected="val => selectedCollections = val"
                    @close="showCollectionModal = false"
                    @createCollection="openCreateCollectionModal"
                />
                <CreateCollectionModal
                    v-if="showCreateCollectionModal"
                    @created="collectionCreated"
                    @close="showCreateCollectionModal = false"
                />
            </Teleport>
        </div>
    </AppLayout>
</template>
