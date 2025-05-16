<script setup>
import { ref, nextTick, onMounted, watch } from 'vue'
import { useInfiniteScroll } from '@vueuse/core'
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
    await axios.patch(`/messenger/conversations/${props.conversationId}/read`);
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
