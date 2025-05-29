<script setup>
import notifInfo from '@/utils/notifInfo'
import { useNotifications } from '@/stores/useNotifications.js'

const props = defineProps({ n: Object, mobile: Boolean })
const { markRead } = useNotifications()
const info = notifInfo(props.n)
</script>

<template>
    <div
        class="p-3 flex gap-3 hover:bg-base-200 notif-item"
        :data-id="n.id"
        :class="{ 'opacity-80': n.read_at }"
        @mouseenter="markRead(n.id)"
        @click="mobile && markRead(n.id)"
    >
        <img :src="n.data.avatar" class="w-8 h-8 rounded-full">
        <div class="flex-1 text-sm leading-snug">
            <span v-html="info.html" />
            <div v-if="info.extra" class="text-xs italic opacity-70 mt-1">{{ info.extra }}</div>
            <div class="text-xs opacity-60 mt-1">{{ new Date(n.created_at).toLocaleString() }}</div>
        </div>
        <span v-if="!n.read_at" class="badge badge-xs badge-primary self-start" />
    </div>
</template>
