<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    conversation: Object,
    otherUser: Object,
    showUserMenu: Boolean,
})

const emit = defineEmits(['toggle-user-menu'])

const isDialog = computed(() => props.conversation?.type === 'dialog')
</script>

<template>
    <header class="flex items-center gap-4 border-base-300 bg-base-100 relative">
        <div v-if="otherUser" class="flex items-center gap-4 cursor-pointer" @click="$emit('toggle-user-menu')">
            <img
                :src="otherUser.profile_photo_url"
                alt="avatar"
                class="w-12 h-12 rounded-full object-cover"
            />
            <h2 class="text-xl font-semibold">
                {{ isDialog ? otherUser.name : (conversation?.title || 'Группа') }}
            </h2>
        </div>
        <slot name="menu" />
    </header>
</template>
