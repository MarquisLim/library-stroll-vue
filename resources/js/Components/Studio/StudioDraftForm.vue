<!-- StudioDraftForm.vue -->
<template>
    <div class="flex flex-col md:flex-row gap-4 relative">
        <!-- Левая колонка: drag and drop + превью -->
        <div class="md:w-1/2 border-2 border-dashed border-gray-500 p-4 rounded text-center cursor-pointer relative"
             @drop.prevent="onDrop"
             @dragover.prevent>
            <p v-if="!previewUrl">Перетащите файл сюда или нажмите для выбора.</p>
            <input type="file" class="hidden" ref="fileInput" @change="onFileChange"/>
            <button class="btn btn-primary mt-2" @click="browseFile">Выбрать файл</button>

            <div v-if="previewUrl" class="mt-2 text-center">
                <template v-if="fileType === 'image'">
                    <img :src="previewUrl" class="max-h-40 mx-auto" loading="lazy" alt="Preview Image"/>
                </template>
                <template v-else-if="fileType === 'video'">
                    <video :src="previewUrl" class="max-h-40 mx-auto" controls></video>
                </template>
                <template v-else>
                    <img :src="previewUrl" class="max-h-40 mx-auto" loading="lazy" alt="Preview Other"/>
                </template>
            </div>
            <button v-if="selectedDraftId && previewUrl" class="btn btn-success absolute top-4 right-4" @click="$emit('publish')">Опубликовать</button>
        </div>

        <!-- Правая колонка: поля формы -->
        <div class="md:w-1/2 flex flex-col space-y-4">

            <div class="space-y-2">
                <label>Заголовок</label>
                <input type="text" class="input input-bordered w-full bg-gray-700 text-white"
                       :disabled="isDisabled" :value="title"
                       @input="e => $emit('update:title', e.target.value)" />
            </div>

            <div class="space-y-2">
                <label>Описание</label>
                <textarea class="textarea textarea-bordered w-full bg-gray-700 text-white"
                          :disabled="isDisabled"
                          :value="description"
                          @input="e => $emit('update:description', e.target.value)"></textarea>
            </div>

            <div class="space-y-2">
                <label class="flex items-center space-x-2">
                    <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="is_adult" @change="e => $emit('update:is_adult', e.target.checked)">
                    <span>Контент для взрослых</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="has_ai" @change="e => $emit('update:has_ai', e.target.checked)">
                    <span>Контент создан AI</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="is_private" @change="e => $emit('update:is_private', e.target.checked)">
                    <span>Приватный</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="allow_download" @change="e => $emit('update:allow_download', e.target.checked)">
                    <span>Разрешить скачивание</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="allow_comments" @change="e => $emit('update:allow_comments', e.target.checked)">
                    <span>Разрешить комментарии</span>
                </label>
            </div>

            <div class="space-y-2">
                <label>Теги</label>
                <tag-input
                    :initial-tags="tags"
                    :suggestions="tagSuggestions"
                    @tagsUpdated="t => $emit('tagsUpdated', t)"
                    @search="q => $emit('searchTags', q)"
                    @addTag="tagName => $emit('addTag', tagName)"
                />
            </div>

            <div class="space-y-2 relative">
                <button class="btn btn-secondary" @click="$emit('update:showCollectionModal', true)" :disabled="isDisabled">
                    Выбрать коллекции
                </button>

                <collection-modal
                    v-if="showCollectionModal"
                    :collections="collections"
                    :selected-collections="selectedCollections"
                    :position="{ top: 100, left: 100 }"
                    @close="$emit('update:showCollectionModal', false)"
                    @selected="vals => $emit('update:selectedCollections', vals)"
                    @createCollection="$emit('createCollection')"
                />
            </div>

        </div>
    </div>
</template>

<script>
import { ref } from 'vue'
import TagInput from '@/Components/Tags/TagInput.vue'
import CollectionModal from '@/Components/Collections/CollectionSelector.vue'

export default {
    components: { TagInput, CollectionModal },
    props: {
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
        selectedCollections: Array,
        collections: Array,
        showCollectionModal: Boolean,
        confirmingDraftDeletion: Boolean,
        tagSuggestions: Array,
        collectionSuggestions: Array
    },
    computed: {
        isDisabled() {
            return !this.selectedDraftId || !this.previewUrl
        }
    },
    setup() {
        const fileInput = ref(null)
        return { fileInput }
    },
    methods: {
        onFileChange(e) {
            const file = e.target.files[0]
            if(file) {
                this.$emit('uploadFile', file)
            }
        },
        browseFile() {
            this.$refs.fileInput.click()
        },
        onDrop(e) {
            const file = e.dataTransfer.files[0]
            if(file) {
                this.$emit('uploadFile', file)
            }
        }
    }
}
</script>

<style scoped>
/* Добавьте любые дополнительные стили при необходимости */
</style>
