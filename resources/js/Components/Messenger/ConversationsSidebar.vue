<template>
    <aside class="w-72 border-r border-base-300 overflow-y-auto">
        <Link
            v-for="chat in items"
            :key="chat.id"
            :href="route('messenger.index', chat.id)"
            class="flex items-center px-4 py-3 hover:bg-base-200 transition"
            :class="{ 'bg-base-200': chat.id === activeId }"
        >
            <!-- Аватар слева -->
            <template v-if="chat.type === 'dialog'">
                <img
                    :src="avatarUrl(chat)"
                    alt="avatar"
                    class="w-10 h-10 rounded-full object-cover mr-3"
                />
            </template>
            <template v-else>
                <img
                    :src="randomGroupAvatar(chat.id)"
                    alt="group avatar"
                    class="w-10 h-10 rounded-full object-cover mr-3"
                />
            </template>

            <!-- Название и превью -->
            <div class="flex-1">
                <h3 class="font-medium">{{ displayTitle(chat) }}</h3>
                <p class="text-xs text-base-content/60 truncate">{{ preview(chat) }}</p>
            </div>

            <!-- Бейдж непрочитанных -->
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
    activeId: { type: Number, default: null }
})
const meId = usePage().props.auth.user.id

function displayTitle(c) {
    if (c.type === 'dialog') {
        const p = c.users.find(u => u.id !== meId)
        return p?.name ?? '—'
    }
    return c.title || 'Группа'
}

function avatarUrl(c) {
    if (c.type === 'dialog') {
        const p = c.users.find(u => u.id !== meId)
        return p?.profile_photo_url || defaultAvatar()
    }
    return defaultAvatar()
}

function randomGroupAvatar(id) {
    // Используем сервис генерации случайных аватаров по id чата
    return `https://i.pravatar.cc/40?u=${id}`
}

function defaultAvatar() {
    return 'https://i.pravatar.cc/40?u=default'
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
