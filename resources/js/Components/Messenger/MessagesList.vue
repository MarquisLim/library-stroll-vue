<template>
    <div
        ref="container"
        class="h-full min-h-0 w-full overflow-y-auto overscroll-contain px-4 py-2"
    >
        <div class="space-y-4">
            <MessageItem
                v-for="message in messages"
                :key="message.id"
                :msg="message"
                :last-read-id="currentReadId"
                @reply="handleReply"
                @go-to="scrollToMessage"
            />
            <div ref="endOfMessages" aria-hidden="true" />
        </div>
    </div>
</template>

<script setup>
import { ref, nextTick, onMounted, onUnmounted } from 'vue'
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

let echoChannel = null
let resizeObserver = null
let initialScrollDone = false

const earliestId = () => messages.value[0]?.id ?? 0
const hasMore = ref(true)

const page = usePage()
const authUserId = page.props.auth.user?.id

async function loadPrevious() {
    if (!hasMore.value || !initialScrollDone) {
        return
    }

    const el = container.value
    if (!el) {
        return
    }

    const beforeId = earliestId()
    if (!beforeId || beforeId === 1) {
        hasMore.value = false
        return
    }

    const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight

    const response = await axios.get(
        `/messenger/conversations/${props.conversationId}/messages`,
        { params: { before_id: beforeId } }
    )
    const data = response.data
    if (data.length) {
        messages.value = [...data, ...messages.value]
        await nextTick()
        el.scrollTop = el.scrollHeight - el.clientHeight - distanceFromBottom
    } else {
        hasMore.value = false
    }
}

function scrollToBottom() {
    const el = container.value
    if (!el) {
        return false
    }

    el.scrollTop = el.scrollHeight
    return el.scrollHeight - el.clientHeight - el.scrollTop < 2
}

async function scrollToBottomUntilStable(maxFrames = 12) {
    await nextTick()

    for (let i = 0; i < maxFrames; i += 1) {
        await new Promise((resolve) => requestAnimationFrame(resolve))
        if (scrollToBottom()) {
            initialScrollDone = true
            return
        }
    }

    scrollToBottom()
    initialScrollDone = true
}

function observeContainerResize() {
    if (!container.value || resizeObserver) {
        return
    }

    resizeObserver = new ResizeObserver(() => {
        if (!initialScrollDone) {
            if (scrollToBottom()) {
                initialScrollDone = true
            }
        }
    })
    resizeObserver.observe(container.value)
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

async function appendMessage(message) {
    if (!message?.id || messages.value.some(m => m.id === message.id)) {
        return
    }

    messages.value.push(message)
    await nextTick()
    scrollToBottom()

    if (message.user_id !== authUserId) {
        await markAllAsRead(message.id)
    }
}

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

function leaveEchoChannel() {
    if (echoChannel) {
        window.Echo?.leave(`conversation.${props.conversationId}`)
        echoChannel = null
    }
}

function subscribeToEcho() {
    if (!window.Echo || !props.conversationId) {
        return
    }

    leaveEchoChannel()

    echoChannel = window.Echo.private(`conversation.${props.conversationId}`)

    echoChannel
        .listen('.MessageSent', ({ message }) => appendMessage(message))
        .error((error) => {
            console.error('Echo subscription failed for conversation', props.conversationId, error)
        })
}

onMounted(async () => {
    const lastOnScreen = messages.value.length
        ? messages.value[messages.value.length - 1].id
        : 0
    if (lastOnScreen) {
        await markAllAsRead(lastOnScreen)
    }

    observeContainerResize()
    await scrollToBottomUntilStable()

    useInfiniteScroll(container, loadPrevious, {
        distance: 120,
        direction: 'top',
        immediate: false,
        throttleWait: 300,
        canLoadMore: () => hasMore.value && initialScrollDone,
    })

    subscribeToEcho()
})

onUnmounted(() => {
    leaveEchoChannel()
    resizeObserver?.disconnect()
    resizeObserver = null
})

defineExpose({ appendMessage })
</script>
