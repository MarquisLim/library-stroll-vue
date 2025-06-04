<!-- EditArtworkModal.vue -->
<script setup>
import { ref, watch, computed } from 'vue'
import axios from 'axios'
import TagInput from '@/Components/Tags/TagInput.vue'

const props = defineProps({
    artwork: Object,
    open: Boolean
})
const emit = defineEmits(['saved', 'close'])

const dialogRef = ref(null)
const title = ref('')
const description = ref('')
const is_private = ref(false)
const is_adult = ref(false)
const has_ai = ref(false)
const allow_download = ref(true)
const allow_comments = ref(true)
const tags = ref([])
const suggestions = ref([])
const preview = ref('')
const file = ref(null)

const isVideoDraft = computed(() => {
    if (!props.artwork) return false
    if (file.value) return file.value.type.startsWith('video')
    return props.artwork.type === 'video'
})

const canSave = computed(() => {
    if (!props.artwork) return false
    return (
        title.value.trim() !== '' &&
        (!file.value || isVideoDraft.value || preview.value || props.artwork.thumb_url)
    )
})

watch(
    () => props.open,
    (v) => {
        if (v && props.artwork) {
            title.value = props.artwork.title || ''
            description.value = props.artwork.description || ''
            is_adult.value = props.artwork.is_adult
            is_private.value = props.artwork.is_private
            has_ai.value = props.artwork.has_ai
            allow_download.value = props.artwork.allow_download
            allow_comments.value = props.artwork.allow_comments
            tags.value = (props.artwork.tags ?? []).map((t) => t.name)
            preview.value = props.artwork.type === 'image' ? props.artwork.thumb_url : ''
            file.value = null
            suggestions.value = []
            dialogRef.value.showModal()
        } else {
            dialogRef.value?.close()
        }
    }
)

async function searchTags(q) {
    if (!q.trim()) {
        suggestions.value = []
        return
    }
    const { data } = await axios.get('/studio/search-tags', { params: { query: q } })
    suggestions.value = data.tags.map((t) => t.name)
}

function onFile(e) {
    const f = e.target.files[0]
    if (!f) return
    file.value = f
    preview.value = f.type.startsWith('image') ? URL.createObjectURL(f) : ''
}

async function save() {
    if (!canSave.value) return
    const { data } = await axios.post(`/studio/update-draft/${props.artwork.id}`, {
        title: title.value,
        description: description.value,
        is_private: is_private.value,
        is_adult: is_adult.value,
        has_ai: has_ai.value,
        allow_download: allow_download.value,
        allow_comments: allow_comments.value,
        tags: tags.value
    })
    Object.assign(props.artwork, data.artwork)
    if (file.value && !props.artwork.is_published) {
        const fd = new FormData()
        fd.append('file', file.value)
        fd.append('draftId', props.artwork.id)
        await axios.post('/studio/upload-file', fd)
    }
    emit('saved')
    emit('close')
}

async function publish() {
    if (!canSave.value) return
    try {
        await axios.post(`/studio/publish/${props.artwork.id}`)
        emit('saved')
        emit('close')
    } catch (e) {
        window.$toast?.error(e.response?.data?.error || 'Ошибка публикации')
    }
}

function onBgClick(e) {
    if (e.target === e.currentTarget) {
        emit('close')
    }
}
</script>

<template>
    <dialog
        ref="dialogRef"
        class="modal"
        @close="emit('close')"
        @click.self="onBgClick"
    >
        <div class="modal-box w-full max-w-3xl rounded-lg p-6 space-y-4 overflow-y-auto max-h-[90vh] relative">
            <form method="dialog">
                <button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">✕</button>
            </form>
            <h2 class="text-lg font-semibold flex items-center mb-2">
                <i class="mdi mdi-pencil-outline mr-2"></i>Редактирование
            </h2>
            <div class="mb-4" v-if="props.artwork && props.artwork.is_published">
                <video
                    v-if="props.artwork.type === 'video'"
                    :poster="props.artwork.thumb_url"
                    :src="props.artwork.original_url"
                    controls
                    class="w-full rounded"
                />
                <img
                    v-else
                    :src="preview || props.artwork.thumb_url"
                    class="w-full rounded"
                />
            </div>
            <div
                v-if="props.artwork && !props.artwork.is_published"
                class="bg-base-200 bg-opacity-40 p-3 rounded border border-base-300 text-base-content text-center space-y-2"
            >
                <input
                    id="fileInput"
                    type="file"
                    class="hidden"
                    accept="image/*,video/*"
                    @change="onFile"
                />
                <label for="fileInput" class="btn btn-sm btn-outline">
                    <i class="mdi mdi-upload mr-1"></i>Заменить файл
                </label>
                <img
                    v-if="preview && !isVideoDraft"
                    :src="preview"
                    class="mt-2 max-h-56 object-contain mx-auto rounded"
                />
                <div
                    v-else-if="isVideoDraft"
                    class="flex items-center justify-center mt-2 text-base-content/70"
                >
                    <i class="mdi mdi-video-outline text-3xl mr-2"></i>Видео выбрано
                </div>
            </div>
            <div class="space-y-3" v-if="props.artwork">
                <div>
                    <label for="title" class="block text-sm mb-1 text-base-content/70">Название *</label>
                    <input
                        id="title"
                        v-model="title"
                        class="input input-bordered w-full"
                        placeholder="Введите название"
                    />
                </div>
                <div>
                    <label for="description" class="block text-sm mb-1 text-base-content/70">Описание</label>
                    <textarea
                        id="description"
                        v-model="description"
                        rows="3"
                        class="textarea textarea-bordered w-full"
                        placeholder="Введите описание"
                    ></textarea>
                </div>
                <div class="flex flex-wrap gap-4">
                    <label class="label cursor-pointer gap-2">
                        <input type="checkbox" v-model="is_private" class="checkbox checkbox-sm" />
                        <span class="label-text">Приватный</span>
                    </label>
                    <label class="label cursor-pointer gap-2">
                        <input type="checkbox" v-model="is_adult" class="checkbox checkbox-sm" />
                        <span class="label-text">Взрослый контент+</span>
                    </label>
                    <label class="label cursor-pointer gap-2">
                        <input type="checkbox" v-model="has_ai" class="checkbox checkbox-sm" />
                        <span class="label-text">AI-generated</span>
                    </label>
                    <label class="label cursor-pointer gap-2">
                        <input type="checkbox" v-model="allow_download" class="checkbox checkbox-sm" />
                        <span class="label-text">Скачивание</span>
                    </label>
                    <label class="label cursor-pointer gap-2">
                        <input type="checkbox" v-model="allow_comments" class="checkbox checkbox-sm" />
                        <span class="label-text">Комментарии</span>
                    </label>
                </div>
                <TagInput
                    :initial-tags="tags"
                    :suggestions="suggestions"
                    @tagsUpdated="(val) => (tags = val)"
                    @search="searchTags"
                />
            </div>
            <div class="flex justify-end gap-2 pt-4" v-if="props.artwork">
                <button class="btn" @click="emit('close')">Отмена</button>
                <button class="btn btn-primary" :disabled="!canSave" @click="save">Сохранить</button>
                <button
                    v-if="!props.artwork.is_published"
                    class="btn btn-success"
                    :disabled="!canSave"
                    @click="publish"
                >
                    Опубликовать
                </button>
            </div>
        </div>
    </dialog>
</template>
