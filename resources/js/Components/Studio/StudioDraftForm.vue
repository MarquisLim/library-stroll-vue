<template>
    <div class="relative">
        <button v-if="selectedDraftId && previewUrl" class="btn btn-success absolute top-4 right-4" @click="$emit('publish')">Опубликовать</button>

        <!-- Зона для drag and drop -->
        <div class="border-2 border-dashed border-gray-500 p-4 rounded text-center cursor-pointer"
             @drop.prevent="onDrop"
             @dragover.prevent
        >
            <p v-if="!previewUrl">Перетащите файл сюда или нажмите для выбора.</p>
            <input type="file" class="hidden" ref="fileInput" @change="onFileChange"/>
            <button class="btn btn-primary mt-2" @click="browseFile">Выбрать файл</button>

            <div v-if="previewUrl" class="mt-2 text-center">
                <img v-if="fileType==='image'" :src="previewUrl" class="max-h-40 mx-auto" loading="lazy" />
                <video v-else-if="fileType==='video'" :src="previewUrl" class="max-h-40 mx-auto" controls></video>
                <img v-else :src="previewUrl" class="max-h-40 mx-auto" loading="lazy" />
            </div>
        </div>

        <div class="space-y-2 mt-4">
            <label>Заголовок</label>
            <input type="text" class="input input-bordered w-full" :disabled="isDisabled" :value="title" @input="e=>$emit('update:title',e.target.value)" />
        </div>

        <div class="space-y-2">
            <label>Описание</label>
            <textarea class="textarea textarea-bordered w-full" :disabled="isDisabled" :value="description" @input="e=>$emit('update:description',e.target.value)"></textarea>
        </div>

        <div class="space-y-2">
            <label class="flex items-center space-x-2">
                <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="is_adult" @change="e=>$emit('update:is_adult',e.target.checked)">
                <span>Контент для взрослых</span>
            </label>
            <label class="flex items-center space-x-2">
                <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="has_ai" @change="e=>$emit('update:has_ai',e.target.checked)">
                <span>Контент создан AI</span>
            </label>
            <label class="flex items-center space-x-2">
                <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="is_private" @change="e=>$emit('update:is_private',e.target.checked)">
                <span>Приватный</span>
            </label>
            <label class="flex items-center space-x-2">
                <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="allow_download" @change="e=>$emit('update:allow_download',e.target.checked)">
                <span>Разрешить скачивание</span>
            </label>
            <label class="flex items-center space-x-2">
                <input type="checkbox" class="checkbox checkbox-primary" :disabled="isDisabled" :checked="allow_comments" @change="e=>$emit('update:allow_comments',e.target.checked)">
                <span>Разрешить комментарии</span>
            </label>
        </div>

        <div class="space-y-2">
            <label>Теги</label>
            <div :class="isDisabled?'opacity-50 pointer-events-none':''">
                <tag-input :initialTags="tags" @tagsUpdated="t=> $emit('tagsUpdated',t)" />
            </div>
        </div>

        <div class="space-y-2">
            <label>Коллекции</label>
            <div class="flex items-center space-x-2" :class="isDisabled?'opacity-50 pointer-events-none':''">
                <select multiple class="select select-bordered w-full" :disabled="isDisabled" :value="selectedCollections" @change="updateCollections($event)">
                    <option v-for="col in collections" :key="col.id" :value="col.id">{{ col.name }}</option>
                </select>
                <button class="btn btn-secondary" @click="$emit('update:showCollectionModal',true)" :disabled="isDisabled">+</button>
            </div>
        </div>

        <collection-modal v-if="showCollectionModal" @close="$emit('update:showCollectionModal',false)" @created="c=>$emit('collectionCreated',c)" />
    </div>
</template>

<script>
import TagInput from '@/Components/Tags/TagInput.vue'
import CollectionModal from '@/Components/Modals/CollectionModal.vue'
import { ref } from 'vue'

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
        confirmingDraftDeletion: Boolean
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
            if (file) {
                this.$emit('uploadFile', file)
            }
        },
        browseFile() {
            this.$refs.fileInput.click()
        },
        onDrop(e) {
            const file = e.dataTransfer.files[0]
            if (file) {
                this.$emit('uploadFile', file)
            }
        },
        updateCollections(e) {
            const options = [...e.target.options].filter(o => o.selected).map(o => parseInt(o.value))
            this.$emit('update:selectedCollections', options)
        }
    }
}
</script>
