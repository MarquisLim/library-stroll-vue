<script setup>
import { computed } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'

const props = defineProps({
    items: Array,
    // можно передать текущий активный ID для подсветки
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
        return p?.profile_photo_url
    }
    // Берём первые три аватарки группы
    return c.users.slice(0,3).map(u => u.profile_photo_url)
}

function preview(c) {
    const m = c.last_message;
    if (!m) return 'Чат создан';

    if (m.artwork) {
        return '🎨 Артворк';
    }

    if (m.attachments?.length) {
        const att = m.attachments[0];
        if (att.mime.startsWith('image/')) return '🖼 Фото';
        if (att.mime.startsWith('video/')) return '🎥 Видео';
        return '📎 Файл';
    }

    if (m.body) {
        return m.body.length > 30
            ? m.body.slice(0, 30) + '…'
            : m.body;
    }

    return '';
}
</script>

<template>
    <aside class="w-72 border-r border-base-300 overflow-y-auto">
        <Link
            v-for="c in items"
            :key="c.id"
            :href="route('messenger.index', c.id)"
            class="flex items-center px-4 py-3 hover:bg-base-200 transition"
            :class="{ 'bg-base-200': c.id === activeId }"
        >
            <!-- Аватарка -->
            <template v-if="c.type==='dialog'">
                <img
                    :src="avatarUrl(c)"
                    alt="avatar"
                    class="w-10 h-10 rounded-full object-cover mr-3"
                />
            </template>
            <template v-else>
                <div class="flex -space-x-2 mr-3">
                    <img
                        v-for="(url, idx) in avatarUrl(c)"
                        :key="idx"
                        :src="url"
                        class="w-8 h-8 rounded-full border-2 border-base-100 object-cover"
                    />
                </div>
            </template>

            <!-- Заголовок и превью -->
            <div class="flex-1">
                <h3 class="font-medium">{{ displayTitle(c) }}</h3>
                <p class="text-xs text-base-content/60 truncate">
                    {{ preview(c) }}
                </p>
            </div>

            <!-- счётчик непрочитанных -->
            <span v-if="c.unread" class="badge badge-primary">{{ c.unread }}</span>
        </Link>
    </aside>
</template>
