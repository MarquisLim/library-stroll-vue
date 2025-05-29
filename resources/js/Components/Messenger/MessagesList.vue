<script setup>
import { ref, nextTick, onMounted, watch } from 'vue'
import { useInfiniteScroll, useIntersectionObserver  } from '@vueuse/core'
import axios from 'axios'
import MessageItem from './MessageItem.vue'

const props = defineProps({
    conversationId: Number,
    initial: {
        type: Array,
        default: () => []
    }
})

const msgs = ref([...props.initial])
const container = ref(null)
const endOfMessages = ref(null)
const observerStops  = new Map()

const earliestId = () => msgs.value[0]?.id ?? 0
const hasMore = ref(true)

async function loadPrevious () {
    if (!hasMore.value) return

    const before = earliestId()
    if (!before) return

    const { data } = await axios.get(
        `/messenger/conversations/${props.conversationId}/messages`,
        { params: { before_id: before } }
    )

    if (data.length) {
        const have = new Set(msgs.value.map(m => m.id))
        msgs.value = [...data.filter(m => !have.has(m.id)), ...msgs.value]
        if (data.length < 50) hasMore.value = false
    } else {
        hasMore.value = false
    }
}

function scrollToBottom() {
    if (endOfMessages.value) {
        endOfMessages.value.scrollIntoView({ behavior: 'smooth', block: 'end' })
    }
}

function watchItem(el, msg) {
    if (observerStops.has(msg.id)) return
    const stop = useIntersectionObserver(
        el,
        async ([entry]) => {
            if (!entry.isIntersecting || !msg.unread_for_me) return
            msg.unread_for_me = false
            el.dataset.unread = 'false'
            stop()
            observerStops.delete(msg.id)

            await axios.patch(
                `/messenger/conversations/${props.conversationId}/read`,
                { last_read_id: msg.id }
            )
            window.dispatchEvent(new CustomEvent('conv-read', {
                detail: { id: props.conversationId }
            }))
        },
        { root: container.value, threshold: 1 }
    )
    observerStops.set(msg.id, stop)
}

onMounted(async() => {
    useInfiniteScroll(container, loadPrevious, { distance:50, direction:'top' })

    await nextTick()
    scrollToBottom()

    window.Echo.private(`conversation.${props.conversationId}`)
        .listen('.MessageSent', ({ message }) => {
            msgs.value.push(message)
            nextTick(() => {
                scrollToBottom()
                const el = container.value.querySelector(`[data-id="${message.id}"]`)
                if (el) watchItem(el, message)
            })
        })

    nextTick(() => {
        container.value.querySelectorAll('[data-id]').forEach(div => {
            const id  = +div.dataset.id
            const msg = msgs.value.find(m => m.id === id)
            if (msg?.unread_for_me) watchItem(div, msg)
        })
    })

});

watch(msgs, nextTick, { flush:'post' })

function markVisibleAsRead (entries) {
    entries.forEach(async e => {
        if (!e.isIntersecting) return
        const el = e.target
        if (el.dataset.unread !== 'true') return

        el.dataset.unread = 'false'               // убираем точку визуально
        const id = +el.dataset.id
        const m  = msgs.value.find(x => x.id === id)
        if (m) m.unread_for_me = false

        // PATCH / обновляем pivot
        await axios.patch(
            `/messenger/conversations/${props.conversationId}/read`,
            { last_read_id: id }                    // ← backend уже был
        )

        // обновляем счётчик в sidebar
        const conv = conversations.find(c => c.id === props.conversationId)
        if (conv) conv.unread = Math.max(0, conv.unread - 1)
    })
}
</script>

<template>
    <div
        ref="container"
        class="flex-1 overflow-y-auto px-4 space-y-4 my-2 scroll-smooth"
    >
        <div ref="container"
             class="flex-1 overflow-y-auto px-4 space-y-4 my-2 scroll-smooth">
            <MessageItem v-for="m in msgs"
                         :key="m.id"
                         :msg="m"
                         :ref="el => el && watchItem(el, m)"/>
            <div ref="endOfMessages"></div>
        </div>
        <div ref="endOfMessages"></div>
    </div>
</template>
