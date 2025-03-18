<!-- StudioIndex.vue (в примере код идёт как StudioDraft.vue, но суть — главная страница студии) -->
<template>
    <AppLayout title="Studio">
<!--        <template #header>-->
<!--            <h2 class="font-semibold text-xl text-gray-100 leading-tight">-->
<!--                Драфты-->
<!--            </h2>-->
<!--        </template>-->

        <!-- min-h-screen и flex-колонки, чтобы на мобилках располагаться вертикально.
             Меняем space-x-4 на gap-4 и используем flex-col для мобильных, md:flex-row для шире. -->
        <div class="p-4 bg-gray-900 min-h-screen text-white">
            <div class="flex flex-col md:flex-row gap-4">
                <!-- Левая панель: список черновиков. На мобильных - w-full, на больших - 1/4 -->
                <div class="w-full md:w-1/4">
                    <div class="mb-4 md:text-right">
                        <button class="btn btn-purple" @click="showCreateModal = true">
                            Создать искусство
                        </button>
                    </div>
                    <StudioDraftList
                        :drafts="drafts"
                        :selectedDraftId="selectedDraftId"
                        @selectDraft="selectDraft"
                        @confirmDeleteDraft="confirmDeleteDraft"
                        @reorder-drafts="reorderDrafts"
                    />
                </div>

                <!-- Правая панель: форма редактирования. На мобильных - w-full, на больших - 3/4 -->
                <div class="w-full md:w-3/4 relative bg-gray-800 p-4 rounded shadow">
                    <!-- StudioDraftForm -->
                    <StudioDraftForm
                        :selectedDraftId="selectedDraftId"
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
                        :selectedCollections="selectedCollections"
                        :collections="collections"
                        :showCollectionModal="showCollectionModal"
                        :confirmingDraftDeletion="confirmingDraftDeletion"
                        :tagSuggestions="tagSuggestions"
                        :collectionSuggestions="collectionSuggestions"
                        @update:title="updateTitle"
                        @update:description="updateDescription"
                        @update:is_adult="updateIsAdult"
                        @update:has_ai="updateHasAi"
                        @update:is_private="updateIsPrivate"
                        @update:allow_download="updateAllowDownload"
                        @update:allow_comments="updateAllowComments"
                        @tagsUpdated="tagsUpdated"
                        @update:selectedCollections="updateSelectedCollections"
                        @update:showCollectionModal="val => showCollectionModal = val"
                        @collectionCreated="collectionCreated"
                        @publish="publishDraft"
                        @destroyDraft="destroyDraft"
                        @uploadFile="uploadFile"
                        @searchTags="searchTags"
                        @searchCollections="searchCollections"
                        @addTag="addTag"
                        @addCollection="addCollectionToSelected"
                        @createCollection="openCreateCollectionModal"
                    />

                    <div class="text-gray-400 text-sm mt-2">
                        <span v-if="successMessage && successMessage.includes('Сохранено')">
                            {{ successMessage }}
                        </span>
                        <span v-else-if="successMessage && successMessage.includes('Сохранение')">
                            {{ successMessage }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Модальное окно «Создать искусство» -->
        <div v-if="showCreateModal" class="modal modal-open">
            <div class="modal-box bg-gray-800 text-white">
                <h3 class="font-bold text-lg">Создать искусство</h3>
                <p class="my-2 text-gray-300">
                    Загрузите файл для создания нового черновика:
                </p>
                <div
                    class="border-2 border-dashed border-gray-500 p-4 rounded text-center"
                    @drop.prevent="onDropFileCreate"
                    @dragover.prevent
                >
                    <p v-if="!tempFile">
                        Перетащите файл или нажмите для выбора
                    </p>
                    <p v-else>
                        Выбран файл: {{ tempFile.name }}
                    </p>
                    <input
                        type="file"
                        ref="createFileInput"
                        class="hidden"
                        @change="onFileChangeCreate"
                    />
                    <button class="btn mt-2 btn-secondary" @click="browseFileCreate">
                        Выбрать файл
                    </button>
                </div>
                <div class="modal-action">
                    <button class="btn" @click="showCreateModal = false">Отмена</button>
                    <button class="btn btn-primary" @click="createArtFromFile" :disabled="!tempFile">
                        Создать
                    </button>
                </div>
            </div>
        </div>

        <!-- Модальное окно удаления черновика -->
        <div v-if="confirmingDraftDeletion" class="modal modal-open">
            <div class="modal-box bg-gray-800 text-white">
                <h3 class="font-bold text-lg">Удалить черновик?</h3>
                <p class="text-gray-300">
                    Вы уверены, что хотите удалить этот черновик?
                </p>
                <div class="modal-action">
                    <button class="btn" @click="cancelDelete">Отмена</button>
                    <button class="btn btn-error" @click="destroyDraft">Удалить</button>
                </div>
            </div>
        </div>

        <!-- Модальное окно создания коллекции -->
        <CreateCollectionModal
            v-if="showCreateCollectionModal"
            @close="showCreateCollectionModal = false"
            @created="collectionCreated"
        />

        <!-- Ошибки и сообщения -->
        <div class="fixed bottom-0 left-0 m-4 space-y-2 w-64 z-50">
            <div
                v-for="(e, i) in errorMessages"
                :key="i"
                class="bg-red-500 text-white px-4 py-2 rounded"
            >
                {{ e }}
            </div>
            <div
                v-if="successMessage && !successMessage.includes('Сохранение')"
                class="bg-green-500 text-white px-4 py-2 rounded"
            >
                {{ successMessage }}
            </div>
        </div>

        <!-- Индикатор загрузки -->
        <div
            v-if="isUploading"
            class="fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-gray-800 text-white p-4 shadow rounded z-50"
        >
            <p class="mb-2">Загрузка файла: {{ uploadProgress }}%</p>
            <div class="w-64 bg-gray-700 rounded h-4">
                <div
                    class="bg-blue-500 h-4 rounded"
                    :style="{ width: uploadProgress + '%' }"
                ></div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'

import AppLayout from "@/Layouts/AppLayout.vue";
import StudioDraftList from '@/Components/Studio/StudioDraftList.vue'
import StudioDraftForm from '@/Components/Studio/StudioDraftForm.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'

const page = usePage()

// Списки черновиков, коллекций и т.д.
const drafts = ref([...page.props.drafts])
const collections = ref([...page.props.collections])

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
const confirmingDraftDeletion = ref(false)
const showCollectionModal = ref(false)
const isUploading = ref(false)
const uploadProgress = ref(0)
const errorMessages = ref([])
const successMessage = ref(null)
const showCreateModal = ref(false)
const tempFile = ref(null)

const showCreateCollectionModal = ref(false)

let autoSaveTimeout = null

const tagSuggestions = ref([])
const collectionSuggestions = ref([])

/* ===== Функции для авто-сохранения, выбора черновика, загрузки файлов и т.д. ===== */

function scheduleAutoSave() {
    if (autoSaveTimeout) clearTimeout(autoSaveTimeout)
    autoSaveTimeout = setTimeout(performAutoSave, 1000)
    successMessage.value = 'Сохранение...'
}

function performAutoSave() {
    if (selectedDraftId.value && previewUrl.value) {
        axios.post(`/studio/update-draft/${selectedDraftId.value}`, {
            title: title.value,
            description: description.value,
            is_adult: is_adult.value,
            has_ai: has_ai.value,
            is_private: is_private.value,
            allow_download: allow_download.value,
            allow_comments: allow_comments.value,
            tags: tags.value,
            collections: selectedCollections.value
        })
            .then(res => {
                successMessage.value = 'Сохранено'
                updateDraftInLocal(res.data.artwork)
                selectDraft(res.data.artwork.id)
                setTimeout(() => {
                    successMessage.value = null
                }, 3000)
            })
            .catch(handleError)
    } else {
        successMessage.value = null
    }
}

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
        tags.value = draft.tags ? draft.tags.map(t => t.name) : []
        selectedCollections.value = draft.collections
            ? draft.collections.map(c => c.id)
            : []
        previewUrl.value = draft.media && draft.media.length > 0
            ? draft.media[0].original_url
            : null
        fileType.value = determineFileType(previewUrl.value)
    } else {
        resetFields()
    }
}

function determineFileType(url) {
    if (!url) return null
    const ext = url.split('.').pop().toLowerCase()
    if (['jpg','jpeg','png','gif'].includes(ext)) return 'image'
    if (['mp4','mov','avi','webm'].includes(ext)) return 'video'
    return 'other'
}

/* ===== Создание нового черновика ===== */
function onDropFileCreate(e) {
    const file = e.dataTransfer.files[0]
    if (file) tempFile.value = file
}
const createFileInput = ref(null)
function browseFileCreate() {
    createFileInput.value.click()
}
function onFileChangeCreate(e) {
    const file = e.target.files[0]
    if (file) tempFile.value = file
}
function createArtFromFile() {
    if (!tempFile.value) return
    isUploading.value = true
    uploadProgress.value = 0
    const formData = new FormData()
    formData.append('file', tempFile.value)
    axios.post('/studio/upload-file', formData, {
        onUploadProgress: event => {
            uploadProgress.value = Math.round((event.loaded / event.total) * 100)
        }
    })
        .then(res => {
            isUploading.value = false
            uploadProgress.value = 0
            successMessage.value = res.data.message
            updateDraftInLocal(res.data.artwork)
            selectDraft(res.data.artwork.id)
            showCreateModal.value = false
            tempFile.value = null
            setTimeout(() => {
                successMessage.value = null
            }, 3000)
        })
        .catch(err => {
            isUploading.value = false
            uploadProgress.value = 0
            handleError(err)
        })
}

/* ===== Удаление черновика ===== */
function confirmDeleteDraft(id) {
    selectedDraftId.value = id
    confirmingDraftDeletion.value = true
}
function cancelDelete() {
    confirmingDraftDeletion.value = false
}
function destroyDraft() {
    axios.delete(`/studio/draft/${selectedDraftId.value}`)
        .then(res => {
            successMessage.value = res.data.message
            drafts.value = drafts.value.filter(d => d.id !== selectedDraftId.value)
            selectedDraftId.value = null
            resetFields()
            confirmingDraftDeletion.value = false
            setTimeout(() => {
                successMessage.value = null
            }, 3000)
        })
        .catch(handleError)
}

/* ===== Публикация черновика ===== */
function publishDraft() {
    axios.post(`/studio/publish/${selectedDraftId.value}`)
        .then(res => {
            successMessage.value = res.data.message
            drafts.value = drafts.value.filter(d => d.id !== selectedDraftId.value)
            selectedDraftId.value = null
            resetFields()
            setTimeout(() => {
                successMessage.value = null
            }, 3000)
        })
        .catch(handleError)
}

/* ===== Загрузка файла к уже существующему черновику ===== */
function uploadFile(file) {
    isUploading.value = true
    uploadProgress.value = 0
    const formData = new FormData()
    formData.append('file', file)
    if (selectedDraftId.value) {
        formData.append('draftId', selectedDraftId.value)
    }
    axios.post('/studio/upload-file', formData, {
        onUploadProgress: event => {
            uploadProgress.value = Math.round((event.loaded / event.total) * 100)
        }
    })
        .then(res => {
            isUploading.value = false
            uploadProgress.value = 0
            successMessage.value = res.data.message
            updateDraftInLocal(res.data.artwork)
            selectDraft(res.data.artwork.id)
            setTimeout(() => {
                successMessage.value = null
            }, 3000)
        })
        .catch(err => {
            isUploading.value = false
            uploadProgress.value = 0
            handleError(err)
        })
}

/* ===== Сортировка черновиков (drag and drop) ===== */
function reorderDrafts(newOrder) {
    axios.post('/studio/reorder-drafts', { draft_order: newOrder })
        .then(res => {
            successMessage.value = res.data.message
            setTimeout(() => {
                successMessage.value = null
            }, 3000)
        })
        .catch(handleError)
}

/* ===== Работа с коллекциями и тегами ===== */
function collectionCreated(col) {
    collections.value.push(col)
    successMessage.value = 'Коллекция создана'
    setTimeout(() => {
        successMessage.value = null
    }, 3000)
}
function searchTags(query) {
    axios.get('/studio/search-tags?query=' + encodeURIComponent(query))
        .then(res => {
            tagSuggestions.value = res.data.tags.map(t => t.name)
        })
        .catch(err => console.log(err))
}
function searchCollections(query) {
    axios.get('/studio/search-collections?query=' + encodeURIComponent(query))
        .then(res => {
            collectionSuggestions.value = res.data.collections
        })
        .catch(err => console.log(err))
}
function addTag(tagName) {
    if (!tags.value.includes(tagName)) {
        tags.value.push(tagName)
        scheduleAutoSave()
    }
}
function addCollectionToSelected(collectionId) {
    if (!selectedCollections.value.includes(collectionId)) {
        selectedCollections.value.push(collectionId)
        scheduleAutoSave()
    }
}

/* ===== Хелперы для авто-сохранения полей ===== */
function updateTitle(val) {
    title.value = val
    scheduleAutoSave()
}
function updateDescription(val) {
    description.value = val
    scheduleAutoSave()
}
function updateIsAdult(val) {
    is_adult.value = val
    scheduleAutoSave()
}
function updateHasAi(val) {
    has_ai.value = val
    scheduleAutoSave()
}
function updateIsPrivate(val) {
    is_private.value = val
    scheduleAutoSave()
}
function updateAllowDownload(val) {
    allow_download.value = val
    scheduleAutoSave()
}
function updateAllowComments(val) {
    allow_comments.value = val
    scheduleAutoSave()
}
function tagsUpdated(newTags) {
    tags.value = newTags
    scheduleAutoSave()
}
function updateSelectedCollections(val) {
    selectedCollections.value = val
    scheduleAutoSave()
}

/* ===== Открытие модального окна для создания коллекции ===== */
function openCreateCollectionModal() {
    showCreateCollectionModal.value = true
}

/* ===== Сброс полей формы ===== */
function resetFields() {
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
}

/* ===== Обновление черновика в локальном списке ===== */
function updateDraftInLocal(artwork) {
    const idx = drafts.value.findIndex(d => d.id === artwork.id)
    if (idx >= 0) {
        drafts.value.splice(idx, 1, artwork)
    } else {
        drafts.value.push(artwork)
    }
}

/* ===== Обработка ошибок ===== */
function handleError(err) {
    errorMessages.value = []
    successMessage.value = null
    if (err.response && err.response.status === 422) {
        const errs = err.response.data.errors
        for (let field in errs) {
            errorMessages.value.push(...errs[field])
        }
    } else if (err.response && err.response.data && err.response.data.error) {
        errorMessages.value.push(err.response.data.error)
    } else {
        errorMessages.value.push('Произошла ошибка')
    }
    setTimeout(() => {
        errorMessages.value = []
    }, 5000)
}
</script>

<style scoped>
/* Дополнительные стили при необходимости */
</style>
