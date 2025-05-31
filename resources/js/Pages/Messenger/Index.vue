<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { reactive } from 'vue'
import axios from 'axios'
import { usePage, Link, router } from '@inertiajs/vue3'
import {
    ChevronLeftIcon,
    UserIcon,
    ArchiveBoxXMarkIcon,
    ArrowPathIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline'

import AppLayout from '@/Layouts/AppLayout.vue'
import ConversationsSidebar from '@/Components/Messenger/ConversationsSidebar.vue'
import ChatHeader from '@/Components/Messenger/ChatHeader.vue'
import MessagesList from '@/Components/Messenger/MessagesList.vue'
import MessageComposer from '@/Components/Messenger/MessageComposer.vue'
import NewChatModal from '@/Components/Messenger/NewChatModal.vue'

// props & state
const page         = usePage()
const conversation = page.props.conversation
const initMsgs     = page.props.messages || []
const newChatModal = ref(null)
const showUserMenu = ref(false)
const showSidebar  = ref(false)
const authId       = page.props.auth.user.id

// вычисляемый собеседник
const otherUser = computed(() => {
    return conversation?.users?.find(u => u.id !== authId) || null
})

// заблокирован ли мной
const isBlockedByMe = computed(() => !!otherUser.value?.is_blocked_by_me)
// заблокирован ли он меня
const hasBlockedMe = computed(() => otherUser.value?.pivot?.blocked_me === true)

// меню пункты
function toggleBlock() {
    if (!otherUser.value) return
    const userId = otherUser.value.id
    const action = isBlockedByMe.value ? 'unblock' : 'block'
    const text   = isBlockedByMe.value
        ? 'Разблокировать пользователя?'
        : 'Заблокировать пользователя?'
    if (!confirm(text)) return

    axios.post(`/users/${userId}/${action}`)
        .then(() => {
            otherUser.value.pivot.blocked_by_me = !isBlockedByMe.value

            alert(
                otherUser.value.pivot.blocked_by_me
                    ? 'Пользователь заблокирован'
                    : 'Пользователь разблокирован'
            )

            showUserMenu.value = false
        })
}

// удалить чат
function deleteChat() {
    if (!conversation) return
    if (!confirm('Удалить чат? Это действие необратимо.')) return
    axios.delete(`/messenger/conversations/${conversation.id}`)
        .then(() => {
            router.visit(route('messenger.index'), { method: 'get' })
        })
        .catch(() => alert('Не удалось удалить чат'))
}

// подписка на события Pusher/Echo
onMounted(() => {
    const myId = authId
    // обновление счетчика и ласт-месседжа
    page.props.conversations.data.forEach(c => {
        window.Echo.private(`conversation.${c.id}`)
            .listen('.MessageSent', ({ message }) => {
                const conv = conversations.find(x => x.id === c.id)
                if (!conv) return
                conv.last_message = message
                if (message.user_id !== myId && c.id !== conversation?.id) {
                    conv.unread = (conv.unread || 0) + 1
                }
            })
    })
    window.Echo.private(`user.${myId}`)
        .listen('.ConversationCreated', ({ conversation: conv }) => {
            conversations.unshift({ ...conv, unread: 0, last_message: conv.lastMessage })
        })
})

// реактивный список чатов
const conversations = reactive(
    page.props.conversations.data.map(c => ({
        ...c,
        unread: c.unread || 0,
    }))
)

function zeroUnread () {
    const conv = conversations.find(c => c.id === conversation.id)
    if (conv) conv.unread = 0
}
watch(() => conversation?.id, id => {
    if (!id) return
    const conv = conversations.find(c => c.id === id)
    if (conv) conv.unread = 0         // ✔ убрали запрос
})
</script>

<template>
    <AppLayout title="Чат">
        <div class="flex h-full">
            <!-- Sidebar -->
            <aside
                :class="[
                    'bg-base-100 border-r border-base-300 overflow-y-auto transition-transform pt-safe-t',
                    'w-100',
                    'fixed inset-y-0 left-0 z-20 transform',
                    showSidebar ? 'translate-x-0' : '-translate-x-full',
                    'sm:static sm:translate-x-0',
                    'sm:sticky sm:top-16 sm:h-[calc(100vh-4rem)]'
                  ]"
                >
                <div class="p-4 border-b border-base-300">
                    <button @click="newChatModal.open()"
                            class="btn btn-outline btn-sm w-full flex items-center justify-center gap-2">
                        + <span>Новый чат</span>
                    </button>
                </div>
                <ConversationsSidebar :items="conversations" :active-id="conversation?.id" />
            </aside>
            <div v-if="showSidebar" @click="showSidebar = false"
                 class="fixed inset-0 bg-black/50 z-10 pt-safe-t sm:hidden" />

            <!-- Main -->
            <div class="flex-1 flex flex-col">
                <div class="flex items-center border border-base-300 px-2 fixed z-10 bg-base-100/80 w-full">
                    <button @click="showSidebar = !showSidebar" class="sm:hidden p-2">
                        <ChevronLeftIcon class="w-6 h-6"/>
                    </button>
                    <div class="flex items-center justify-between border-base-300 h-16">
                        <ChatHeader
                            v-if="conversation"
                            :conversation="conversation"
                            :other-user="otherUser"
                            :show-user-menu="showUserMenu"
                            @toggle-user-menu="showUserMenu = !showUserMenu"
                        >
                            <template #menu>
                                <div
                                    v-if="showUserMenu"
                                    class="absolute left-6 top-12 mt-2 bg-base-100/80 border border-base-300 rounded shadow w-48 z-10"
                                >
                                    <Link :href="`/profile/${otherUser.id}`"
                                          class="flex items-center gap-2 px-4 py-2 hover:bg-base-200">
                                        <UserIcon class="w-5 h-5"/> Профиль
                                    </Link>
                                    <button @click="toggleBlock"
                                            class="flex items-center gap-2 w-full text-left px-4 py-2 hover:bg-base-200"
                                            :class="isBlockedByMe ? 'text-green-600' : 'text-red-600'">
                                        <component :is="isBlockedByMe ? ArrowPathIcon : ArchiveBoxXMarkIcon" class="w-5 h-5"/>
                                        {{ isBlockedByMe ? 'Разблокировать' : 'Заблокировать' }}
                                    </button>
                                    <button @click="deleteChat"
                                            class="flex items-center gap-2 w-full text-left px-4 py-2 hover:bg-base-200 text-red-600">
                                        <TrashIcon class="w-5 h-5"/> Удалить чат
                                    </button>
                                </div>
                            </template>
                        </ChatHeader>
                    </div>
                </div>

                <!-- Messages -->
                <div class="flex-1 overflow-hidden mt-20">
                    <MessagesList
                        v-if="conversation"
                        :conversation-id="conversation.id"
                        :initial="initMsgs"
                        @read="zeroUnread"
                    />
                    <div v-else class="flex-1 flex items-center justify-center text-base-content/60">
                        Выберите чат слева
                    </div>
                </div>

                <!-- Composer -->
                <div v-if="conversation && !hasBlockedMe" class="border-t border-base-300">
                    <MessageComposer :conversation-id="conversation.id"/>
                </div>
                <div v-else-if="hasBlockedMe" class="text-sm text-center text-base-content/50 py-4">
                    Вы не можете отправлять сообщения, так как вас заблокировали
                </div>

            </div>

            <NewChatModal ref="newChatModal"/>
        </div>
    </AppLayout>
</template>
