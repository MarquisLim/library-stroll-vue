<template>
    <div
        :class="[
      'relative flex items-start mb-4',
      isMine ? 'justify-end space-x-reverse' : 'justify-start'
    ]"
        :data-id="msg.id"
        ref="item"
        @click="toggleMenu"
    >
    <span
        v-if="isUnread"
        class="absolute -left-3 top-2 w-2 h-2 rounded-full bg-primary"
    />
        <img
            :src="msg.user.profile_photo_url"
            class="w-8 h-8 rounded-full object-cover mr-2"
            :class="isMine ? 'ml-2' : ''"
            alt="avatar"
        />
        <div class="max-w-xs relative">
            <div
                :class="[
          'text-xs text-base-content/60 mb-1',
          isMine ? 'text-right' : 'text-left'
        ]"
            >
                {{ msg.user.name }}
            </div>

            <div
                :class="[
          'inline-block p-3 rounded-lg break-all whitespace-pre-wrap',
          isMine ? 'bg-primary text-primary-content' : 'bg-base-200 dark:bg-base-800'
        ]"
            >
                <!-- 1. Цитата, если есть reply_to -->
                <div
                    v-if="msg.reply_to"
                    class="mb-2 p-2 bg-base-300 dark:bg-base-700 rounded text-sm text-base-content/70 cursor-pointer break-all whitespace-pre-wrap"
                    @click.stop="$emit('go-to', msg.reply_to.id)"
                >
                    <div v-if="msg.reply_to.artwork">
                        <strong>Артворк: {{ msg.reply_to.artwork.title }}</strong>
                    </div>
                    <div v-else-if="msg.reply_to.body">
                        {{ msg.reply_to.body }}
                    </div>
                    <div v-else-if="msg.reply_to.attachments.length">
                        {{ msg.reply_to.attachments[0].path.split('/').pop() }}
                    </div>
                </div>

                <!-- 2. Текст сообщения -->
                <p v-if="msg.body" class="break-all whitespace-pre-wrap">
                    {{ msg.body }}
                </p>

                <!-- 3. Арт-вложение -->
                <div v-if="msg.artwork" class="mt-2">
                    <div class="w-full max-w-xs">
                        <ArtworkCard
                            :art="msg.artwork"
                            @like="onLikeArtwork"
                            @open-selector="onOpenSelector"
                        />
                    </div>
                </div>

                <!-- 4. Файловые вложения -->
                <template v-for="attachment in msg.attachments" :key="attachment.id">
                    <img
                        v-if="attachment.mime.startsWith('image/')"
                        :src="attachment.url"
                        class="mt-2 rounded"
                        alt="attachment"
                    />
                    <video
                        v-else-if="attachment.mime.startsWith('video/')"
                        :src="attachment.url"
                        class="mt-2 rounded max-w-full"
                        controls
                    />
                    <div v-else class="mt-2 p-3 bg-blue-100 rounded-lg text-blue-900 break-all whitespace-pre-wrap">
                        <div class="flex items-center space-x-2">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-5 h-5 text-blue-500 shrink-0"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M7 7V4a1 1 0 011-1h6l4 4v12a1 1 0 01-1 1H8a1 1 0 01-1-1v-3m0-4h8"
                                />
                            </svg>
                            <a
                                :href="attachment.url"
                                class="text-blue-700 underline break-all"
                                target="_blank"
                            >
                                {{ attachment.path.split('/').pop() }}
                            </a>
                        </div>
                        <div class="text-xs text-blue-600 mt-1 text-right">
                            {{ formatBytes(attachment.size) }}
                        </div>
                    </div>
                </template>

                <!-- 5. Дата и время -->
                <div
                    :class="[
            'text-[10px] text-base-content/50 mt-1',
            isMine ? 'text-primary-content/80 text-right' : 'text-base-content/50 text-left'
          ]"
                >
                    {{ new Date(msg.created_at).toLocaleString() }}
                </div>
            </div>

            <!-- 6. Меню «Ответить» -->
            <div
                v-if="!isMine && showMenu"
                class="absolute right-0 top-0 bg-base-100 border border-base-300 rounded shadow-md z-10"
            >
                <button
                    @click.stop="emitReply"
                    class="px-2 py-1 text-xs text-blue-600 hover:bg-base-200 w-full text-left"
                >
                    Ответить
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import { useArtworkActions } from '@/stores/useArtworkActions'

const props = defineProps({
    msg: { type: Object, required: true },
    lastReadId: Number
})
const emit = defineEmits(['observe', 'reply', 'go-to'])

const msg = props.msg
const page = usePage()
const myId = page.props.auth.user.id
const isMine = computed(() => msg.user_id === myId)
const isUnread = computed(() => !isMine.value && (props.lastReadId === null || msg.id > props.lastReadId))

const artStore = useArtworkActions()
const item = ref(null)
const showMenu = ref(false)

onMounted(() => {
    const cols = page.props.collections || []
    const arr = Array.isArray(cols)
        ? cols
        : Object.entries(cols).map(([id, title]) => ({ id: +id, title }))
    artStore.setCollections(arr)

    if (!isMine.value && item.value) {
        emit('observe', item.value, msg.id, msg.user_id)
    }
})

function formatBytes(bytes) {
    if (bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B','KB','MB','GB','TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return `${(bytes / Math.pow(k, i)).toFixed(2)} ${sizes[i]}`
}

function onLikeArtwork() {
    artStore.toggleLike(msg.artwork)
}
function onOpenSelector(art, rect) {
    artStore.openSelector(art, rect)
}
function toggleMenu() {
    showMenu.value = !showMenu.value
}
function emitReply() {
    emit('reply', msg)
    showMenu.value = false
}
</script>
