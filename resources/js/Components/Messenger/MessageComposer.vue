<template>
    <div v-if="!isBlockedByThem" class="composer border-t border-base-300 p-4 space-y-2" id="message-composer">
        <!-- Блок цитаты -->
        <div v-if="replyToLocal" class="flex items-center p-2 bg-base-200 rounded">
      <span class="text-sm truncate flex-1">
        {{ replyToLocal.body || (replyToLocal.attachments.length ? replyToLocal.attachments[0].path.split('/').pop() : '') }}
      </span>
            <button @click="clearReply" class="btn btn-xs btn-ghost">
                <XMarkIcon class="w-4 h-4" />
            </button>
        </div>

        <!-- Поле ввода текста -->
        <textarea
            v-model="body"
            rows="2"
            @keydown.enter.prevent="send"
            @keydown.shift.enter.stop
            :disabled="sending"
            class="textarea textarea-bordered w-full resize-none"
            placeholder="Сообщение…"
        />

        <!-- Превью загруженных файлов -->
        <div v-if="files.length" class="flex flex-wrap gap-2">
            <div
                v-for="f in files"
                :key="f.id"
                class="flex items-center bg-base-200 rounded p-2 space-x-2 w-full sm:w-auto"
            >
                <div v-if="f.mime.startsWith('image/')" class="w-12 h-12">
                    <img :src="f.url" class="w-full h-full object-cover rounded" />
                </div>
                <div v-else class="flex items-center justify-center w-12 h-12 bg-base-300 rounded">
                    <PaperClipIcon class="w-6 h-6 text-base-content/70" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="truncate text-xs font-medium">{{ truncateName(getFileName(f.url)) }}</div>
                    <div class="text-[10px] text-base-content/50">{{ formatBytes(f.size) }}</div>
                    <div class="text-[10px] text-base-content/50">{{ getFileType(f.mime) }}</div>
                </div>
                <button @click="removeFile(f.id)" class="self-start">
                    <XMarkIcon class="w-4 h-4 text-red-500" />
                </button>
            </div>
        </div>

        <!-- Сообщение об ошибке и прогресс-бар -->
        <div v-if="uploadError" class="text-sm text-red-600">
            {{ uploadError }}
        </div>
        <div v-if="uploadProgress > 0 && uploadProgress < 100" class="w-full bg-base-200 rounded overflow-hidden">
            <div class="h-2 bg-primary" :style="{ width: uploadProgress + '%' }"></div>
        </div>

        <!-- Кнопки загрузки и отправки -->
        <div class="flex items-center gap-2 flex-wrap">
            <label class="btn btn-sm btn-ghost flex items-center gap-1">
                <PaperClipIcon class="w-5 h-5" />
                <span class="hidden sm:inline">Вложение</span>
                <input type="file" class="hidden" @change="onFile" />
            </label>
            <button class="ml-auto btn btn-primary btn-sm flex items-center gap-1" @click="send" :disabled="sending">
                <ArrowUpTrayIcon class="w-5 h-5 rotate-45" />
                <span class="hidden sm:inline">Отправить</span>
            </button>
<!--            <button @click="togglePicker" class="btn btn-sm btn-ghost ml-2">-->
<!--                <EmojiHappyIcon class="w-5 h-5" />-->
<!--            </button>-->
        </div>

        <!-- Селектор эмодзи -->
        <Picker
            v-if="showPicker"
            :data="emojiData"
            @emoji-select="addEmoji"
            :theme="'light'"
            :style="{ position: 'absolute', bottom: '4rem', right: '1rem', zIndex: 50 }"
        />
    </div>

    <div v-else class="text-sm text-center text-base-content/50 py-4">
        Вы не можете отправлять сообщения, так как вас заблокировали
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import { Picker } from 'emoji-mart-vue-fast'
import emojiDataRaw from '@emoji-mart/data'
import {
    PaperClipIcon,
    ArrowUpTrayIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline'

// Инициализируем данные для эмодзи
const emojiData = ref(null)
onMounted(() => {
    emojiData.value = emojiDataRaw
})

const props = defineProps({
    conversationId: Number,
    replyTo: { type: Object, default: null }
})
const emit = defineEmits(['sent'])

const body = ref('')
const files = ref([])
const uploadProgress = ref(0)
const uploadError = ref(null)
const showPicker = ref(false)
const replyToLocal = ref(null)
const sending = ref(false)

const conversation = usePage().props.conversation
const authId = usePage().props.auth.user.id

const otherUser = computed(() =>
    conversation?.users?.find(u => u.id !== authId)
)
const isBlockedByThem = computed(() =>
    otherUser.value?.has_blocked_me === true
)

// Слежка за replyTo из родителя
watch(
    () => props.replyTo,
    newMsg => {
        replyToLocal.value = newMsg
        if (newMsg) {
            const composer = document.getElementById('message-composer')
            if (composer) composer.scrollIntoView({ behavior: 'smooth', block: 'end' })
        }
    },
    { immediate: true }
)

function clearReply() {
    replyToLocal.value = null
    emit('sent', null)
}

async function send() {
    if (sending.value) return
    if (!body.value.trim() && !files.value.length) return

    sending.value = true
    try {
        const { data: msg } = await axios.post(
            `/messenger/conversations/${props.conversationId}/messages`, {
                body: body.value,
                reply_to_id: replyToLocal.value?.id || null,
                attachments: files.value.map(f => f.id)
            }
        )

        body.value = ''
        files.value = []
        replyToLocal.value = null
        emit('sent', msg)
    } catch (e) {
        console.error(e)
    } finally {
        sending.value = false
    }
}

async function onFile(e) {
    uploadError.value = null
    uploadProgress.value = 0

    const file = e.target.files[0]
    if (!file) return

    const maxBytes = 20 * 1024 * 1024
    if (file.size > maxBytes) {
        uploadError.value = 'Файл превышает 20 МБ'
        return
    }

    const fd = new FormData()
    fd.append('file', file)

    try {
        const response = await axios.post(
            '/messenger/attachments',
            fd,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
                onUploadProgress: progressEvent => {
                    if (progressEvent.total) {
                        uploadProgress.value = Math.round((progressEvent.loaded * 100) / progressEvent.total)
                    }
                }
            }
        )

        // В ответе есть { id, url, mime, size }
        files.value.push(response.data)
        uploadProgress.value = 0
    } catch (err) {
        if (err.response?.data?.errors?.file?.length) {
            uploadError.value = err.response.data.errors.file[0]
        } else {
            uploadError.value = 'Ошибка при загрузке'
        }
        uploadProgress.value = 0
    }
}

function removeFile(id) {
    files.value = files.value.filter(f => f.id !== id)
}

function togglePicker() {
    showPicker.value = !showPicker.value
}

function addEmoji(emoji) {
    body.value += emoji.native
    showPicker.value = false
}

function getFileName(url) {
    return url.split('/').pop()
}

function truncateName(name) {
    const max = 12
    if (name.length <= max) return name
    return name.slice(0, max) + '…'
}

function getFileType(mime) {
    return mime.split('/')[1] || mime
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return `${(bytes / Math.pow(k, i)).toFixed(2)} ${sizes[i]}`
}
</script>

<style scoped>
/* В мобильной версии нам важно, чтобы превью красиво сжимались */
@media (max-width: 640px) {
    .composer .flex-wrap > div {
        flex: 1 1 100%;
    }
}
</style>
