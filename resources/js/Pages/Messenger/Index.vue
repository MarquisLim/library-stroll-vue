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
                    <button
                        @click="newChatModal.open()"
                        class="btn btn-outline btn-sm w-full flex items-center justify-center gap-2"
                    >
                        + <span>Новый чат</span>
                    </button>
                </div>
                <ConversationsSidebar
                    :items="conversations"
                    :active-id="conversation?.id"
                />
            </aside>

            <!-- Overlay для мобильной версии -->
            <div
                v-if="showSidebar"
                @click="showSidebar = false"
                class="fixed inset-0 bg-black/50 z-10 pt-safe-t sm:hidden"
            />

            <!-- Main area -->
            <div class="flex-1 flex flex-col">

                <!-- Header (скрыт, если conversation = null) -->
                <div class="flex items-center border border-base-300 px-2 fixed z-10 bg-base-100 bg-opacity-80 backdrop-blur-md w-full">
                    <button
                        @click="showSidebar = !showSidebar"
                        class="sm:hidden p-2"
                    >
                        <ChevronLeftIcon class="w-6 h-6" />
                    </button>

                    <div class="flex items-center border-base-300 h-16 w-full relative px-2">

                        <!-- Сам заголовок -->
                        <ChatHeader
                            v-if="conversation"
                            :conversation="conversation"
                            :other-user="otherUser"
                        />

                        <!-- Правее заголовка — обёрнутый в relative дропдаун -->
                        <div v-if="conversation" class="relative">
                            <div class="dropdown">
                                <div
                                    tabindex="0"
                                    role="button"
                                    class="btn btn-ghost btn-circle p-2"
                                >
                                    <EllipsisHorizontalIcon class="w-6 h-6 text-base-content/70" />
                                </div>
                                <ul
                                    tabindex="0"
                                    class="dropdown-content menu bg-base-100 rounded-box z-50 w-48 p-2 shadow-sm border border-base-300"
                                >
                                    <!-- Если это диалог -->
                                    <template v-if="conversation.type === 'dialog'">
                                        <li>
                                            <Link
                                                :href="`/profile/${otherUser.id}`"
                                                class="flex items-center gap-2 px-4 py-2 hover:bg-base-200 text-sm"
                                            >
                                                Профиль
                                            </Link>
                                        </li>
                                        <li>
                                            <button
                                                @click="toggleBlock"
                                                class="flex items-center gap-2 px-4 py-2 hover:bg-base-200 text-sm"
                                                :class="isBlockedByMe
                                                ? 'text-green-600'
                                                : 'text-red-600'"
                                            >
                                                {{ isBlockedByMe ? 'Разблокировать' : 'Заблокировать' }}
                                            </button>

                                        </li>
                                        <li>
                                            <button
                                                @click="deleteChat"
                                                class="flex items-center gap-2 w-full text-left px-4 py-2 hover:bg-base-200 text-red-600 text-sm"
                                            >
                                                Удалить чат
                                            </button>
                                        </li>
                                    </template>

                                    <!-- Если это группа -->
                                    <template v-else>
                                        <li>
                                            <button
                                                @click="groupSettings.open()"
                                                class="flex items-center gap-2 w-full text-left px-4 py-2 hover:bg-base-200 text-sm"
                                            >
                                                Редактировать группу
                                            </button>
                                        </li>
                                        <li>
                                            <button
                                                @click="leaveGroup"
                                                class="flex items-center gap-2 w-full text-left px-4 py-2 hover:bg-base-200 text-red-600 text-sm"
                                            >
                                                Выйти из группы
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                        <!-- /кнопка «три точки» -->

                    </div>
                </div>
                <!-- /Header -->

                <!-- Chat body or placeholder -->
                <div class="flex-1 overflow-hidden mt-20">
                    <MessagesList
                        v-if="conversation"
                        :conversation-id="conversation.id"
                        :initial="initMsgs"
                        :last-read-id="conversation.pivot?.last_read_id"
                        @read="handleRead"
                        @reply="onReplyFromList"
                    />
                    <div
                        v-else
                        class="flex-1 flex flex-col items-center justify-center text-base-content/60 space-y-4"
                    >
                        <!-- Mobile button -->
                        <button
                            @click="showSidebar = true"
                            class="sm:hidden flex flex-col items-center px-6 py-4 border border-base-300 rounded-lg hover:bg-base-200 transition"
                        >
                            <img src="/niko.webp" alt="niko" class="w-24 h-24 mb-2" />
                            <span class="font-medium mb-1">Выберите чат слева</span>
                            <ChevronLeftIcon class="w-6 h-6" />
                        </button>

                        <!-- Desktop hint -->
                        <div class="hidden sm:flex flex-row items-center space-x-4">
                            <ChevronLeftIcon class="w-8 h-8 animate-pulse" />
                            <img src="/niko.webp" alt="niko" class="w-24 h-24" />
                            <span class="text-lg font-medium">Выберите чат слева</span>
                        </div>
                    </div>
                </div>

                <!-- Composer -->
                <div v-if="conversation">
                    <MessageComposer
                        v-if="conversation.type === 'dialog' ? !hasBlockedMe : true"
                        :conversation-id="conversation.id"
                        :reply-to="replyToObject"
                        @sent="clearReply"
                    />
                    <div
                        v-else-if="conversation.type === 'dialog' && hasBlockedMe"
                        class="text-sm text-center text-base-content/50 py-4"
                    >
                        Вы не можете отправлять сообщения, так как вас заблокировали
                    </div>
                </div>
                <div
                    v-else-if="hasBlockedMe"
                    class="text-sm text-center text-base-content/50 py-4"
                >
                    Вы не можете отправлять сообщения, так как вас заблокировали
                </div>
            </div>

            <!-- Модалки -->
            <NewChatModal
                ref="newChatModal"
                :recent-users="recentUsers"
            />
            <GroupSettingsModal
                v-if="conversation"
                ref="groupSettings"
                :conversation="conversation"
            />
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { reactive } from 'vue'
import axios from 'axios'
import { usePage, Link, router } from '@inertiajs/vue3'
import { ChevronLeftIcon, EllipsisHorizontalIcon } from '@heroicons/vue/24/outline'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConversationsSidebar from '@/Components/Messenger/ConversationsSidebar.vue'
import ChatHeader from '@/Components/Messenger/ChatHeader.vue'
import MessagesList from '@/Components/Messenger/MessagesList.vue'
import MessageComposer from '@/Components/Messenger/MessageComposer.vue'
import NewChatModal from '@/Components/Messenger/NewChatModal.vue'
import GroupSettingsModal from '@/Components/Messenger/GroupSettingsModal.vue'

const page = usePage()
const conversation = page.props.conversation
const initMsgs = page.props.messages || []
const newChatModal = ref(null)
const groupSettings = ref(null)
const showSidebar = ref(false)
const authId = page.props.auth.user.id

const otherUser = computed(() => {
    return conversation?.users?.find((u) => u.id !== authId) || null
})

const isBlockedByMe = computed(() =>
    otherUser.value?.is_blocked_by_me === true
)

const hasBlockedMe = computed(() =>
    otherUser.value?.has_blocked_me === true
)
function toggleBlock() {
    if (!otherUser.value) return
    const id = otherUser.value.id
    const action = isBlockedByMe.value ? 'unblock' : 'block'
    const confirmText = isBlockedByMe.value
        ? 'Разблокировать пользователя?'
        : 'Заблокировать пользователя?'
    if (!confirm(confirmText)) return

    axios.post(`/users/${id}/${action}`)
        .then(() => {
            otherUser.value.is_blocked_by_me = !isBlockedByMe.value
        })
}


function deleteChat() {
    if (!conversation) return
    if (!confirm('Удалить чат? Это действие необратимо.')) return
    axios
        .delete(`/messenger/conversations/${conversation.id}`)
        .then(() => {
            router.visit(route('messenger.index'), { method: 'get' })
        })
}

const conversations = reactive(
    page.props.conversations.data.map((item) => ({
        ...item,
        unread: item.unread || 0
    }))
)

function handleRead({ delta, newLastReadId }) {
    const conv = conversations.find((item) => item.id === conversation.id)
    if (conv) {
        conv.unread = Math.max(conv.unread - delta, 0)
    }
    if (conversation?.pivot) {
        conversation.pivot.last_read_id = newLastReadId
    }
}

const replyToObject = ref(null)
function onReplyFromList(message) {
    replyToObject.value = message
}
function clearReply() {
    replyToObject.value = null
}

const recentUsers = computed(() => {
    const map = new Map()
    for (const conv of page.props.conversations.data) {
        if (conv.type !== 'dialog') continue
        for (const u of conv.users) {
            if (u.id === authId) continue
            if (!map.has(u.id)) {
                map.set(u.id, {
                    id: u.id,
                    name: u.name,
                    avatar: u.profile_photo_url
                })
            }
        }
    }
    return Array.from(map.values())
})

async function leaveGroup() {
    try {
        await axios.post(
            route(`messenger.conversations.${conversation.id}.leave`),
            {}
        )
        groupSettings.value.close()
        router.visit(route('messenger.index'))
    } catch {}
}

onMounted(() => {
    const myId = authId

    page.props.conversations.data.forEach((item) => {
        window.Echo.private(`conversation.${item.id}`)
            .listen('.MessageSent', ({ message }) => {
                const conv = conversations.find(x => x.id === item.id)
                if (!conv) return

                conv.last_message = message

                if (message.user_id !== myId && item.id !== conversation?.id) {
                    conv.unread = (conv.unread || 0) + 1
                }

                const idx = conversations.indexOf(conv)
                if (idx > 0) {
                    conversations.splice(idx, 1)
                    conversations.unshift(conv)
                }
            })
    })
    window.Echo.private(`user.${myId}`).listen(
        '.ConversationCreated',
        ({ conversation: conv }) => {
            conversations.unshift({
                ...conv,
                unread: 0,
                last_message: conv.lastMessage
            })
        }
    )
})

watch(
    () => conversation?.id,
    async (newId) => {
        if (!newId) return

        const lastId = conversation.pivot?.last_read_id
            ? conversation.pivot.last_read_id
            : conversation.last_message_id

        // PATCH /messenger/conversations/{conversation}/read
        try {
            await axios.patch(
                route('messenger.conversations.read', conversation.id),
                { message_id: lastId }
            )
            // локальное обнуление непрочитанных
            const conv = conversations.find(c => c.id === conversation.id)
            if (conv) conv.unread = 0
        } catch (e) {
            console.error('Не удалось пометить сообщения прочитанными', e)
        }
    },
    { immediate: true }
)
</script>
