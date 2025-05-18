<script setup>
import { ref } from 'vue'
import TagInput from '@/Components/Tags/TagInput.vue'

const props = defineProps({
    selectedDraftId: Number,
    previewUrl: String,
    fileType: String,
    title: String,
    description: String,
    is_adult: Boolean,
    has_ai: Boolean,
    is_private: Boolean,
    allow_download: Boolean,
    allow_comments: Boolean,
    tags: Array,
    tagSuggestions: Array,
    isUploading: Boolean,
    uploadProgress: Number,
    showCollectionModal: Boolean,
})

const emit = defineEmits([
    'uploadFile',
    'publish',
    'update:title',
    'update:description',
    'update:is_adult',
    'update:has_ai',
    'update:is_private',
    'update:allow_download',
    'update:allow_comments',
    'tagsUpdated',
    'searchTags',
    'update:showCollectionModal'
])

const fileInput = ref(null)

function browseFile()      { fileInput.value.click() }
function onFileChange(e)   { if (e.target.files[0]) emit('uploadFile', e.target.files[0]) }
function onDrop(e)         { if (e.dataTransfer.files[0]) emit('uploadFile', e.dataTransfer.files[0]) }
function emitPublish()     { emit('publish') }
function emitUpdateTitle(v){ emit('update:title', v) }
function emitUpdateDescription(v){ emit('update:description', v) }
function emitUpdateIsAdult(v)    { emit('update:is_adult', v) }
function emitUpdateHasAi(v)      { emit('update:has_ai', v) }
function emitUpdateIsPrivate(v)  { emit('update:is_private', v) }
function emitUpdateAllowDownload(v){ emit('update:allow_download', v) }
function emitUpdateAllowComments(v){ emit('update:allow_comments', v) }
function emitTagsUpdated(v)      { emit('tagsUpdated', v) }
function emitSearchTags(q)       { emit('searchTags', q) }
function emitShowCollectionModal(v){ emit('update:showCollectionModal', v) }

defineExpose({ fileInput })
</script>

<template>
    <div class="flex flex-col md:flex-row gap-4">

        <div
            class="w-full md:w-1/2 border-2 border-dashed border-base-content/50 p-4 rounded text-center bg-base-200 relative cursor-pointer"
            @click="browseFile"
            @drop.prevent="onDrop"
            @dragover.prevent
        >
            <input
                type="file"
                class="hidden"
                ref="fileInput"
                @change="onFileChange"
            />
            <p v-if="!previewUrl">Перетащите файл или нажмите</p>
            <p v-else class="font-semibold">Файл загружен</p>

            <div
                v-if="isUploading"
                class="absolute inset-0 bg-base-300/75 flex items-center justify-center rounded"
            >
                <progress
                    class="progress w-3/4 progress-primary"
                    :value="uploadProgress"
                    max="100"
                />
            </div>

            <div v-if="previewUrl" class="mt-4">
                <template v-if="fileType==='image'">
                    <img
                        :src="previewUrl"
                        class="w-full max-h-80 object-contain rounded"
                        loading="lazy"
                    />
                </template>
                <template v-else-if="fileType==='video'">
                    <video
                        :src="previewUrl"
                        class="w-full max-h-80 object-contain rounded"
                        controls
                    />
                </template>
            </div>
        </div>

        <div class="w-full md:w-1/2 space-y-4">
            <div>
                <label class="block mb-1">
                    Заголовок <span class="text-error">*</span>
                </label>
                <input
                    type="text"
                    :value="title"
                    @input="emitUpdateTitle($event.target.value)"
                    class="input input-bordered w-full bg-base-200 text-base-content"
                    :disabled="!previewUrl"
                />
            </div>

            <div>
                <label class="block mb-1">Описание</label>
                <textarea
                    :value="description"
                    @input="emitUpdateDescription($event.target.value)"
                    class="textarea textarea-bordered w-full bg-base-200 text-base-content"
                    rows="3"
                    :disabled="!previewUrl"
                />
            </div>

            <div class="grid grid-cols-2 gap-2">
                <label class="flex items-center space-x-2">
                    <input
                        type="checkbox"
                        :checked="is_adult"
                        @change="emitUpdateIsAdult($event.target.checked)"
                        class="checkbox checkbox-primary"
                        :disabled="!previewUrl"
                    />
                    <span>Взрослый</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input
                        type="checkbox"
                        :checked="has_ai"
                        @change="emitUpdateHasAi($event.target.checked)"
                        class="checkbox checkbox-secondary"
                        :disabled="!previewUrl"
                    />
                    <span>AI-generated</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input
                        type="checkbox"
                        :checked="is_private"
                        @change="emitUpdateIsPrivate($event.target.checked)"
                        class="checkbox checkbox-accent"
                        :disabled="!previewUrl"
                    />
                    <span>Приватный</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input
                        type="checkbox"
                        :checked="allow_download"
                        @change="emitUpdateAllowDownload($event.target.checked)"
                        class="checkbox checkbox-info"
                        :disabled="!previewUrl"
                    />
                    <span>Скачивание</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input
                        type="checkbox"
                        :checked="allow_comments"
                        @change="emitUpdateAllowComments($event.target.checked)"
                        class="checkbox checkbox-warning"
                        :disabled="!previewUrl"
                    />
                    <span>Комментарии</span>
                </label>
            </div>

            <div>
                <label class="block mb-1">Теги</label>
                <tag-input
                    :initial-tags="tags"
                    :suggestions="tagSuggestions"
                    @tagsUpdated="emitTagsUpdated"
                    @search="emitSearchTags"
                    :class="!previewUrl ? 'opacity-50 pointer-events-none' : ''"
                />
            </div>

            <div class="flex items-center justify-between">
                <button
                    class="btn btn-secondary"
                    @click="emitShowCollectionModal(true)"
                    :disabled="!previewUrl"
                >
                    Выбрать коллекции
                </button>
                <button
                    class="btn btn-success"
                    @click="emitPublish"
                    :disabled="!title.trim() || !previewUrl"
                >
                    Опубликовать
                </button>
            </div>
        </div>
    </div>
</template>
