<script setup>
import { ref, nextTick, onMounted, watch } from 'vue'
import {useInfiniteScroll, useThrottleFn} from '@vueuse/core'
import axios from 'axios'
import MessageItem from './MessageItem.vue'

const props = defineProps({
    conversationId: Number,
    initial: {
        type: Array,
        default: () => []
    }
})
const emit = defineEmits(['read'])

const msgs = ref([...props.initial])
const container = ref(null)
const endOfMessages = ref(null)
const latestSeen = ref(0)
const sendRead = useThrottleFn(async () => {
    if (!latestSeen.value) return
    await axios.patch(
        route('messenger.conversations.read', props.conversationId),
        { message_id: latestSeen.value }
    )
    emit('read')                       // оповестили родителя -> он обнулит счётчик
}, 800)

function markSeen(id) {
    if (id > latestSeen.value) latestSeen.value = id
    sendRead()
}

const earliestId = () => msgs.value[0]?.id ?? 0
const hasMore = ref(true)

async function loadPrevious () {
    if (!hasMore.value) return

    const before = earliestId()
    if (!before || before === 1) {
        hasMore.value = false
        return
    }

    const { data } = await axios.get(
        `/messenger/conversations/${props.conversationId}/messages`,
        { params: { before_id: before } }
    )

    if (data.length) {
        msgs.value = [...data, ...msgs.value]
    } else {
        hasMore.value = false
    }
}

function scrollToBottom() {
    if (endOfMessages.value) {
        endOfMessages.value.scrollIntoView({ behavior: 'smooth', block: 'end' })
    }
}

onMounted(() => {
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                markSeen(+e.target.dataset.id)
                io.unobserve(e.target)
            }
        })
    }, { root: container.value, threshold: 0.6 })

    function observeIfIncoming(message) {
        if (message.user_id === window.Laravel.user.id) return // мои не нужны
        // ищем DOM-элемент по data-id
        const el = container.value?.querySelector(`[data-id="${message.id}"]`)
        if (el) io.observe(el)
    }

    nextTick(() => {
        container.value
            .querySelectorAll('[data-incoming="1"]')
            .forEach(el => io.observe(el))
    })
})

onMounted(async() => {
    useInfiniteScroll(container, loadPrevious, { distance: 50, direction: 'top', immediate: false, throttleWait: 300 });

    await nextTick()
    scrollToBottom()

    window.Echo
        .private(`conversation.${props.conversationId}`)
        .listen('.MessageSent', payload => {
            msgs.value.push(payload.message);
            nextTick(scrollToBottom)
        });
});

watch(
    () => msgs.value.length,
    async () => {
        await nextTick()
        scrollToBottom()
    },
    { flush: 'post' }
)
</script>

<template>
    <div
        ref="container"
        class="flex-1 overflow-y-auto px-4 space-y-4 my-2 scroll-smooth"
    >
        <MessageItem
            v-for="m in msgs"
            :key="m.id"
            :msg="m"
        />
        <div ref="endOfMessages"></div>
    </div>
</template>
