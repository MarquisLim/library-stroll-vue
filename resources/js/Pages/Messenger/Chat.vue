<script setup>
import { ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import ChatHeader from '@/Components/Messenger/ChatHeader.vue'
import MessagesList from '@/Components/Messenger/MessagesList.vue'
import MessageComposer from '@/Components/Messenger/MessageComposer.vue'

const page = usePage()
const conversation = ref(page.props.conversation)
const initMsgs = ref(page.props.messages)
</script>

<template>
    <AppLayout>
        <div class="flex h-full">
            <div class="my-2">
                <ChatHeader :conversation="conversation" />
            </div>

            <div class="flex flex-col flex-1">
                <div class="flex-1 overflow-hidden">
                    <MessagesList
                        :conversation-id="conversation.id"
                        :initial="initMsgs"
                    />
                </div>
                <MessageComposer :conversation-id="conversation.id" />
            </div>
        </div>
    </AppLayout>
</template>
