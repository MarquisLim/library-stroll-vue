<!-- resources/js/Components/Messenger/ChatHeader.vue -->
<template>
    <div class="flex items-center gap-3">
        <template v-if="conversation.type === 'dialog'">
            <img
                v-if="otherUser?.profile_photo_url"
                :src="otherUser.profile_photo_url"
                alt="avatar"
                class="w-8 h-8 rounded-full object-cover"
            />
            <div v-else class="w-8 h-8 bg-base-200 text-base-content rounded-full flex items-center justify-center">
                <UserIcon class="w-5 h-5 text-base-content/40" />
            </div>
            <h2 class="text-lg font-semibold">{{ otherUser?.name || '—' }}</h2>
        </template>

        <template v-else>
            <img
                v-if="conversation.avatar_url"
                :src="conversation.avatar_url"
                alt="group avatar"
                class="w-8 h-8 rounded-full object-cover"
            />
            <div v-else class="w-8 h-8 bg-base-200 text-base-content rounded-full flex items-center justify-center">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="w-5 h-5 text-base-content/40"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m0 0A4 4 0 0115 16v4m-6-4v4m6-4a4 4 0 00-6 0"
                    />
                </svg>
            </div>
            <div class="flex flex-col leading-tight">
                <h2 class="text-lg font-semibold">{{ conversation.title || 'Группа' }}</h2>
                <span class="text-xs text-base-content/60">
          {{ conversation.users.length }} участник{{ conversation.users.length === 1 ? '' : 'ов' }}
        </span>
            </div>
        </template>
    </div>
</template>

<script setup>
import { UserIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    conversation: { type: Object, required: true },
    otherUser: { type: Object, default: null }
})
</script>
