<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import { Picker } from 'emoji-mart-vue-fast'
import emojiDataRaw from '@emoji-mart/data'

const emojiData = ref(null)
onMounted(() => {
    emojiData.value = emojiDataRaw
})

const props = defineProps({ conversationId: Number })

const body     = ref('')
const files    = ref([])
const showPicker = ref(false)
const replyTo    = ref(null)
const sending = ref(false)

const conversation = usePage().props.conversation
const authId = usePage().props.auth.user.id

const otherUser = computed(() =>
    conversation?.users?.find(u => u.id !== authId)
)

const isBlockedByThem = computed(() =>
    otherUser.value?.has_blocked_me === true
)

async function send () {
    if (sending.value) return
    if (!body.value.trim() && !files.value.length) return
    sending.value = true
    const { data: msg } = await axios.post(
        `/messenger/conversations/${props.conversationId}/messages`, {
            body: body.value,
            reply_to_id: replyTo.value?.id,
            attachments: files.value.map(f => f.id)
        })
    body.value = ''
    files.value = []
    replyTo.value = null
    sending.value = false
}

async function onFile(e) {
    const file = e.target.files[0]
    if (!file) return
    const fd = new FormData()
    fd.append('file', file)
    const { data } = await axios.post('/messenger/attachments', fd,
        { headers: { 'Content-Type':'multipart/form-data' }})
    files.value.push(data)
}

function addEmoji(emoji) {
    body.value += emoji.native
    showPicker.value = false
}
</script>

<template>
    <div v-if="!isBlockedByThem" class="composer border-t border-base-300 p-4 space-y-2">
        <div v-if="replyTo" class="flex items-center p-2 bg-base-200 rounded">
            <span class="text-sm truncate flex-1">{{ replyTo.body }}</span>
            <button @click="replyTo=null" class="btn btn-xs btn-ghost">✕</button>
        </div>

        <textarea
            v-model="body"
            rows="2"
            @keydown.enter.prevent="send"
            @keydown.shift.enter.stop
            :disabled="sending"
            class="textarea textarea-bordered w-full resize-none"
            placeholder="Сообщение…"
        />

        <div class="flex gap-2" v-if="files.length">
            <template v-for="f in files" :key="f.id">
                <img v-if="f.mime.startsWith('image/')" :src="f.url" class="w-16 h-16 object-cover rounded"/>
                <div v-else class="w-16 h-16 flex items-center justify-center bg-base-200 rounded">📎</div>
            </template>
        </div>

        <div class="flex items-center gap-2">
            <label class="btn btn-sm btn-ghost">
                📎 <input type="file" class="hidden" @change="onFile">
            </label>
            <button class="btn btn-sm btn-ghost" @click="showPicker = !showPicker">😀</button>
            <button class="ml-auto btn btn-primary btn-sm"
                    @click="send"
                    :disabled="sending"
            >
                Отправить
            </button>
        </div>

        <Picker
            v-if="showPicker"
            :data="emojiData"
            @emoji-select="addEmoji"
            :theme="'light'"
        />
    </div>

    <div v-else class="text-sm text-center text-base-content/50 py-4">
        Вы не можете отправлять сообщения, так как вас заблокировали
    </div>
</template>
