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
    errorMessages : { type:Array, default:() => [] },
    validateFile  : { type:Function, required:true },
    isSaving      : Boolean,
    isDirty       : Boolean,
})

const emit = defineEmits([
    'uploadFile',
    'handleError',
    'validateFile',
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
function onFileChange(e)   {
    const f = e.target.files[0]
    if (!f) return
    if (props.validateFile(f)) emit('uploadFile', f)
}
function onDrop(e) {
    const f = e.dataTransfer.files[0]
    if (!f) return
    if (props.validateFile(f)) emit('uploadFile', f)
}
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

const settings = [
    { key: 'is_private', event: 'update:is_private', title: 'Приватная работа', hint: 'Будет доступна только вам', icon: 'mdi-lock-outline' },
    { key: 'is_adult', event: 'update:is_adult', title: 'Для взрослых', hint: 'Контент с возрастным ограничением', icon: 'mdi-eye-off-outline' },
    { key: 'has_ai', event: 'update:has_ai', title: 'Создано с помощью ИИ', hint: 'Отметка об использовании нейросетей', icon: 'mdi-creation-outline' },
    { key: 'allow_download', event: 'update:allow_download', title: 'Разрешить скачивание', hint: 'Зрители смогут сохранить оригинал', icon: 'mdi-download-outline' },
    { key: 'allow_comments', event: 'update:allow_comments', title: 'Разрешить комментарии', hint: 'Обсуждения и обратная связь', icon: 'mdi-comment-outline' },
]
defineExpose({ fileInput })
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-300 pb-5">
            <div><p class="ui-eyebrow">Редактор</p><h2 class="mt-2 text-xl">{{ title || 'Новая работа' }}</h2></div>
            <span v-if="isSaving" class="workspace-status bg-primary/10 text-primary"><span class="loading loading-spinner loading-xs" /> Сохраняем…</span>
            <span v-else-if="isDirty" class="workspace-status bg-warning/10 text-warning"><i class="mdi mdi-circle-small" /> Есть изменения</span>
            <span v-else-if="selectedDraftId" class="workspace-status bg-success/10 text-success"><i class="mdi mdi-check" /> Сохранено</span>
            <span v-else class="workspace-status bg-base-200 text-base-content/60">Черновик</span>
        </div>
        <div class="studio-editor-grid min-w-0 gap-7">
            <div class="min-w-0 space-y-5">
                <div class="studio-dropzone relative" role="button" tabindex="0" aria-label="Загрузить изображение или видео" @click="browseFile" @keydown.enter.prevent="browseFile" @keydown.space.prevent="browseFile" @drop.prevent="onDrop" @dragover.prevent>
                    <input type="file" class="hidden" ref="fileInput" accept="image/*,video/*" @change="onFileChange" />
                    <template v-if="!previewUrl">
                        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl border border-primary/20 bg-base-100 text-3xl text-primary"><i class="mdi mdi-cloud-upload-outline" /></span>
                        <h3 class="mt-5 text-lg">Здесь начинается ваша работа</h3>
                        <p class="ui-muted mt-3 text-sm">Перетащите изображение или видео<br>или нажмите, чтобы выбрать файл</p>
                        <span class="mt-5 text-xs text-base-content/45">Изображение или видео · до 20 МБ</span>
                    </template>
                    <template v-else>
                        <img v-if="fileType === 'image'" :src="previewUrl" alt="Предпросмотр работы" class="max-h-96 w-full rounded-xl object-contain" />
                        <video v-else-if="fileType === 'video'" :src="previewUrl" controls class="max-h-96 w-full rounded-xl" @click.stop @keydown.stop />
                        <span class="mt-4 text-xs font-semibold text-primary"><i class="mdi mdi-swap-horizontal" /> Нажмите, чтобы заменить файл</span>
                    </template>
                    <div v-if="isUploading" class="absolute inset-0 flex flex-col items-center justify-center gap-4 rounded-2xl bg-base-100/95" role="status">
                        <span class="text-sm font-semibold">Загрузка · {{ uploadProgress }}%</span>
                        <progress class="progress progress-primary w-3/4" :value="uploadProgress" max="100" />
                    </div>
                </div>
                <p v-if="props.errorMessages.length" class="rounded-xl bg-error/10 p-4 text-sm text-error" role="alert">{{ props.errorMessages[0] }}</p>
                <div class="rounded-xl border border-base-300 bg-base-200/50 p-4 text-sm ui-muted"><i class="mdi mdi-information-outline mr-1 text-primary" /> Изменения сохраняются автоматически. Работа появится в галерее после публикации.</div>
            </div>
            <div class="min-w-0 space-y-5">
                <label class="block text-sm font-semibold">Название <span class="text-error">*</span>
                    <input type="text" :value="title" @input="emitUpdateTitle($event.target.value)" placeholder="Дайте работе имя" class="input mt-2 w-full" :disabled="!previewUrl" />
                </label>
                <label class="block text-sm font-semibold">История работы
                    <textarea :value="description" @input="emitUpdateDescription($event.target.value)" placeholder="Расскажите об идее, настроении или процессе создания…" rows="4" class="textarea mt-2 w-full" :disabled="!previewUrl" />
                </label>
                <div><p class="mb-2 text-sm font-semibold">Теги</p><TagInput :initial-tags="tags" :suggestions="tagSuggestions" @tagsUpdated="emitTagsUpdated" @search="emitSearchTags" :class="!previewUrl ? 'opacity-50 pointer-events-none' : ''" /></div>
                <div><p class="mb-3 text-sm font-semibold">Публикация и доступ</p>
                    <div class="space-y-2">
                        <label v-for="setting in settings" :key="setting.key" class="studio-setting cursor-pointer">
                            <span class="flex min-w-0 items-center gap-3"><i :class="['mdi text-xl text-primary', setting.icon]" /><span><span class="block text-sm font-semibold">{{ setting.title }}</span><span class="ui-muted block text-xs font-normal">{{ setting.hint }}</span></span></span>
                            <input type="checkbox" class="toggle toggle-primary toggle-sm shrink-0" :checked="props[setting.key]" @change="emit(setting.event, $event.target.checked)" :disabled="!previewUrl" />
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-5">
            <button class="btn border-base-300 bg-base-100" @click="emitShowCollectionModal(true)" :disabled="!previewUrl"><i class="mdi mdi-folder-plus-outline" /> Выбрать коллекции</button>
            <button class="btn btn-primary" @click="emitPublish" :disabled="!(title || '').trim() || !previewUrl || props.isDirty || props.isSaving || isUploading"><i class="mdi mdi-arrow-top-right" /> Опубликовать работу</button>
        </div>
    </div>
</template>
