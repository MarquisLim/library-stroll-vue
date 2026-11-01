<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
const props = defineProps({ open: Boolean })
const emit = defineEmits(['update:open'])
const page = usePage()
const roles = computed(() => (page.props.auth?.user?.roles || []).map(role => (typeof role === 'string' ? role : role.name).toLowerCase()))
const isAdmin = computed(() => roles.value.some(role => ['admin', 'superadmin'].includes(role)))
const isModerator = computed(() => roles.value.some(role => ['moderator', 'admin', 'superadmin'].includes(role)))
const groups = computed(() => [
    { title: 'Ваше пространство', links: [
        { title: 'Обзор', name: 'dashboard', icon: 'mdi-view-dashboard-outline', path: '/dashboard' },
        { title: 'Создать работу', name: 'studio.index', icon: 'mdi-plus-box-outline', path: '/studio', exact: true },
        { title: 'Мои работы', name: 'studio.manager', icon: 'mdi-image-multiple-outline', path: '/studio/manager' },
        { title: 'Коллекции', name: 'studio.collections', icon: 'mdi-folder-multiple-outline', path: '/studio/collections' },
    ]},
    ...(isModerator.value ? [{ title: 'Модерация', links: [
        { title: 'Жалобы', name: 'moderation.complaints.index', icon: 'mdi-shield-check-outline', path: '/moderation/complaints' },
    ]}] : []),
    ...(isAdmin.value ? [{ title: 'Администрирование', links: [
        { title: 'Пользователи', name: 'admin.users', icon: 'mdi-account-group-outline', path: '/admin/users' },
        { title: 'Все работы', name: 'admin.artworks.manager', icon: 'mdi-image-search-outline', path: '/admin/artworks' },
        { title: 'AI и векторы', name: 'admin.ai.index', icon: 'mdi-brain', path: '/admin/ai' },
    ]}] : []),
])
const active = link => link.exact ? page.url.split('?')[0] === link.path : page.url.startsWith(link.path)
</script>

<template>
    <button v-if="open" class="fixed inset-0 z-40 bg-neutral/50 backdrop-blur-sm lg:hidden" aria-label="Закрыть меню кабинета" @click="emit('update:open', false)" />
    <aside id="workspace-sidebar" :class="['workspace-sidebar', open ? 'flex' : 'hidden lg:flex']" aria-label="Рабочий кабинет">
        <div class="mb-8 flex items-center justify-between px-3">
            <div><p class="text-sm font-extrabold tracking-tight">Рабочий кабинет</p><p class="ui-muted mt-1 text-xs">Идеи. Работы. Возможности.</p></div>
            <button class="btn btn-ghost btn-sm btn-square lg:hidden" aria-label="Закрыть меню кабинета" @click="emit('update:open', false)"><i class="mdi mdi-close text-lg" /></button>
        </div>
        <nav class="min-h-0 flex-1 space-y-7 overflow-y-auto">
            <section v-for="group in groups" :key="group.title">
                <h2 class="workspace-nav-label">{{ group.title }}</h2>
                <div class="space-y-1">
                    <Link v-for="link in group.links" :key="link.name" :href="route(link.name)" class="workspace-nav-link" :aria-current="active(link) ? 'page' : undefined" @click="emit('update:open', false)">
                        <i :class="['mdi', link.icon]" aria-hidden="true" />{{ link.title }}
                    </Link>
                </div>
            </section>
        </nav>
        <Link :href="route('profile.show')" class="mt-6 flex items-center gap-3 rounded-xl border border-base-300 bg-base-200/50 p-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 font-bold text-primary">{{ page.props.auth?.user?.name?.charAt(0) }}</span>
            <span class="min-w-0"><span class="block truncate text-sm font-semibold">{{ page.props.auth?.user?.name }}</span><span class="ui-muted text-xs">Настройки аккаунта</span></span>
            <i class="mdi mdi-cog-outline ml-auto text-base-content/50" />
        </Link>
    </aside>
</template>
