<template>
    <div
        v-if="ready"
        ref="container"
        class="flex-1 overflow-y-auto px-4 space-y-4 my-2 scroll-smooth"
    >
        <MessageItem
            v-for="message in messages"
            :key="message.id"
            :msg="message"
            :last-read-id="currentReadId"
            @reply="handleReply"
            @go-to="scrollToMessage"
        />
        <div ref="endOfMessages"></div>
    </div>
</template>

<script setup>
import { ref, nextTick, onMounted, watch } from 'vue'
import { useInfiniteScroll } from '@vueuse/core'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import MessageItem from './MessageItem.vue'

const props = defineProps({
    conversationId: Number,
    initial: Array,
    lastReadId: Number
})
const emit = defineEmits(['read', 'reply'])

const messages = ref([...props.initial])
const container = ref(null)
const endOfMessages = ref(null)
const currentReadId = ref(props.lastReadId ?? 0)
const ready = ref(false)

const earliestId = () => messages.value[0]?.id ?? 0
const hasMore = ref(true)

async function loadPrevious() {
    if (!hasMore.value) return
    const beforeId = earliestId()
    if (!beforeId || beforeId === 1) {
        hasMore.value = false
        return
    }
    const response = await axios.get(
        `/messenger/conversations/${props.conversationId}/messages`,
        { params: { before_id: beforeId } }
    )
    const data = response.data
    if (data.length) {
        messages.value = [...data, ...messages.value]
        await nextTick()
    } else {
        hasMore.value = false
    }
}

function scrollToBottom() {
    if (endOfMessages.value) {
        endOfMessages.value.scrollIntoView({ behavior: 'smooth', block: 'end' })
    }
}

async function markAllAsRead(upToId) {
    if (upToId > currentReadId.value) {
        await axios.patch(
            route('messenger.conversations.read', props.conversationId),
            { message_id: upToId }
        )
        const delta = upToId - currentReadId.value
        currentReadId.value = upToId
        emit('read', { delta, newLastReadId: upToId })
    }
}

const page = usePage()
const authUserId = page.props.auth.user.id

async function handleReply(message) {
    emit('reply', message)
    await nextTick()
    scrollToBottom()
}

async function scrollToMessage(id) {
    await nextTick()
    if (container.value) {
        const selector = `[data-id="${id}"]`
        const el = container.value.querySelector(selector)
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' })
            el.classList.add('ring-2', 'ring-blue-400')
            setTimeout(() => el.classList.remove('ring-2', 'ring-blue-400'), 1000)
        }
    }
}

onMounted(async () => {
    const lastOnScreen = messages.value.length
        ? messages.value[messages.value.length - 1].id
        : 0
    if (lastOnScreen) {
        await markAllAsRead(lastOnScreen)
    }
    await nextTick()
    useInfiniteScroll(container, loadPrevious, {
        distance: 50,
        direction: 'top',
        immediate: false,
        throttleWait: 300,
    })
    scrollToBottom()
    ready.value = true
    window.Echo
        .private(`conversation.${props.conversationId}`)
        .listen('.MessageSent', async ({ message }) => {
            messages.value.push(message)
            await nextTick()
            scrollToBottom()
            if (message.user_id !== authUserId) {
                await markAllAsRead(message.id)
            }
        })
})

watch(
    () => messages.value.length,
    async () => {
        await nextTick()
        scrollToBottom()
    },
    { flush: 'post' }
)
</script>
