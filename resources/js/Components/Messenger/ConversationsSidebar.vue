<template>
    <aside class="w-72 border-r border-base-300 overflow-y-auto">
        <Link
            v-for="chat in items"
            :key="chat.id"
            :href="route('messenger.index', chat.id)"
            class="flex items-center px-4 py-3 hover:bg-base-200 transition"
            :class="{ 'bg-base-200': chat.id === activeId }"
        >
            <!-- Аватар -->
            <div class="w-10 h-10 rounded-full mr-3 flex-shrink-0">
                <template v-if="chat.type === 'dialog'">
                    <img
                        v-if="getDialogAvatar(chat)"
                        :src="getDialogAvatar(chat)"
                        alt="avatar"
                        class="w-10 h-10 rounded-full object-cover"
                    />
                    <div v-else class="w-10 h-10 bg-base-300 rounded-full"></div>
                </template>
                <template v-else>
                    <img
                        v-if="chat.avatar_url"
                        :src="chat.avatar_url"
                        alt="group avatar"
                        class="w-10 h-10 rounded-full object-cover"
                    />
                    <div v-else class="w-10 h-10 bg-base-300 rounded-full"></div>
                </template>
            </div>

            <!-- Название + бейдж + превью -->
            <div class="flex-1">
                <h3 class="font-medium flex items-center">
                    {{ displayTitle(chat) }}
                    <span
                        v-if="chat.type === 'group'"
                        class="ml-2 text-xs text-base-content/60 border border-base-content/60 rounded px-1"
                    >Группа</span>
                </h3>
                <p class="text-xs text-base-content/60 truncate">{{ preview(chat) }}</p>
            </div>

            <!-- Непрочитанные -->
            <span
                v-if="chat.unread > 0 && chat.id !== activeId"
                class="badge badge-primary"
            >
        {{ chat.unread }}
      </span>
        </Link>
    </aside>
</template>

<script setup>
import { usePage, Link } from '@inertiajs/vue3'

const props = defineProps({
    items: Array,
    activeId: { type: Number, default: null },
})
const meId = usePage().props.auth.user.id

function displayTitle(c) {
    if (c.type === 'dialog') {
        const p = c.users.find(u => u.id !== meId)
        return p?.name || '—'
    }
    return c.title || '—'
}

// возвращает URL аватара собеседника
function getDialogAvatar(c) {
    const p = c.users.find(u => u.id !== meId)
    return p?.profile_photo_url || null
}

function preview(c) {
    const m = c.last_message
    if (!m) return 'Чат создан'
    if (m.artwork) return '🎨 Артворк'
    if (m.attachments?.length) {
        const att = m.attachments[0]
        if (att.mime.startsWith('image/')) return '🖼 Фото'
        if (att.mime.startsWith('video/')) return '🎥 Видео'
        return '📎 Файл'
    }
    if (m.body) {
        return m.body.length > 30 ? m.body.slice(0, 30) + '…' : m.body
    }
    return ''
}
</script>
