<script setup>
import { Head, Link, usePage, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import GlobalModals from '@/Components/Common/GlobalModals.vue'
import Footer from '@/Components/Footer.vue'
import AuthModal from '@/Components/AuthModal.vue'
import SearchOverlay from '@/Components/SearchOverlay.vue'
import NotificationBell from '@/Components/Notifications/NotificationBell.vue'
import UserAvatar from '@/Components/Workspace/UserAvatar.vue'
import {
    HomeIcon, PlusCircleIcon,
    SunIcon, MoonIcon, Bars3Icon,
    ChatBubbleLeftIcon,
    MagnifyingGlassIcon,
    UserIcon,
    Cog6ToothIcon,
    ArrowRightOnRectangleIcon,
    PhotoIcon
} from '@heroicons/vue/24/outline'
import { useArtworkActions } from '@/stores/useArtworkActions'
import { useTheme } from '@/composables/useTheme'

const props         = defineProps({ title: String })
const page          = usePage()
const searchModal = ref(null)
const actions       = useArtworkActions()
const isAuth        = computed(() => !!page.props.auth.user)
const user          = computed(() => page.props.auth.user || {})
const myId          = page.props.auth.user?.id
const { isDark, toggleTheme } = useTheme()
const isLoggingOut = ref(false)

const isMobile      = ref(window.innerWidth < 640)
const isLoading = ref(false)

const stopStartListener = router.on('start', () => (isLoading.value = true))
const stopFinishListener = router.on('finish', () => (isLoading.value = false))
onUnmounted(() => { stopStartListener(); stopFinishListener() })

function resize() { isMobile.value = window.innerWidth < 640 }


// Messages
const subscribedConvs = new Map()
const msgUnread     = ref(page.props.unreadCount || 0)


function subscribeToConversation(id) {
    if (!window.Echo || !myId || subscribedConvs.has(id)) return
    const listener = ({ message }) => {
        if (message.user_id !== myId) msgUnread.value++
    }
    const channel = window.Echo.private(`conversation.${id}`)
    channel.listen('.MessageSent', listener)
    subscribedConvs.set(id, { channel, listener })
}

let userChannel
const onConversationCreated = ({ conversation }) => {
    subscribeToConversation(conversation.id)
    msgUnread.value++
}
onUnmounted(() => {
    subscribedConvs.forEach(({ channel, listener }) => channel.stopListening('.MessageSent', listener))
    subscribedConvs.clear()
    userChannel?.stopListening('.ConversationCreated', onConversationCreated)
})

onMounted(() => window.addEventListener('resize', resize))
onUnmounted(() => window.removeEventListener('resize', resize))

onMounted(() => {
    page.props.conversationIds?.forEach(subscribeToConversation)

    if (window.Echo && myId) {
        userChannel = window.Echo.private(`user.${myId}`)
        userChannel.listen('.ConversationCreated', onConversationCreated)
    }
})

watch(
    () => page.props.conversationIds,
    ids => ids?.forEach(subscribeToConversation)
)

function closeProfileDrawer() {
    const drawer = document.getElementById('profile-drawer')
    if (drawer) {
        drawer.checked = false
    }
}

function logout() {
    if (isLoggingOut.value) return
    isLoggingOut.value = true
    closeProfileDrawer()

    router.post(route('logout'), {}, {
        preserveState: false,
        onFinish: () => {
            isLoggingOut.value = false
        },
        onError: () => {
            isLoggingOut.value = false
        },
    })
}


</script>

<template>
    <div class="ui-app-shell min-h-screen flex flex-col">
        <Head :title="title"/>
        <nav aria-label="Основная навигация" class="ui-site-header sticky top-0 z-40 pt-safe-t">
            <div class="relative mx-auto flex h-16 w-full max-w-screen-2xl items-center gap-2 px-4 sm:gap-4 sm:px-8 lg:px-10">
                <!-- Left -->
                <div class="flex shrink-0 items-center gap-4">

                    <!-- Logo -->
                    <Link :href="route('home')" class="ui-brand" aria-label="LibraryStroll — главная">
                        <span class="ui-brand-mark"><PhotoIcon class="h-5 w-5" /></span>
                        <span class="hidden text-lg xl:inline">Library<span class="text-primary">Stroll</span></span>
                    </Link>

                    <!-- Навигация -->
                    <div class="hidden lg:flex gap-1">
                        <Link :href="route('gallery.index')" class="ui-nav-link" :aria-current="route().current('gallery.*') ? 'page' : undefined">
                            <PhotoIcon class="w-5 h-5" /> Галерея
                        </Link>
                        <Link v-if="isAuth" :href="route('studio.index')" class="ui-nav-link" :aria-current="route().current('studio.*') ? 'page' : undefined">
                            <PlusCircleIcon class="w-5 h-5" /> Студия
                        </Link>
                    </div>
                </div>

                <!-- Center -->
                <div class="hidden min-w-0 flex-1 justify-center lg:flex">
                    <button type="button"
                        class="ui-search-trigger w-full max-w-lg"
                        @click="searchModal?.open()"
                    >
                        <MagnifyingGlassIcon class="h-5 w-5 shrink-0" />
                        <span class="truncate">Работы, авторы, вдохновение…</span>
                    </button>
                </div>

                <SearchOverlay ref="searchModal" />
                <div class="min-w-0 flex-1 py-2 lg:hidden">
                    <button type="button"
                        class="ui-search-trigger w-full"
                        @click="searchModal?.open()"
                    >
                        <MagnifyingGlassIcon class="h-5 w-5 shrink-0" />
                        <span class="truncate">Поиск работ…</span>
                    </button>
                </div>
                <!-- Right -->
                <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                    <button type="button" @click="toggleTheme" class="ui-icon-button" :aria-label="isDark ? 'Включить светлую тему' : 'Включить тёмную тему'" :aria-pressed="isDark">
                        <component :is="isDark?SunIcon:MoonIcon" class="w-6 h-6"/>
                    </button>

                    <NotificationBell v-if="isAuth" />
                    <Link v-if="isAuth" :href="route('messenger.index')" class="ui-icon-button relative" aria-label="Сообщения">
                        <ChatBubbleLeftIcon class="w-6 h-6"/>
                        <span v-if="msgUnread" class="badge badge-xs badge-primary absolute -top-0.5 -right-0.5">
                    {{ msgUnread }}
                </span>
                    </Link>

                    <template v-if="isAuth">
                        <div class="dropdown dropdown-end hidden lg:block">
                            <button type="button" class="ui-icon-button" aria-label="Меню профиля"><UserAvatar :src="user.profile_photo_url" :name="user.name" :size="32" /></button>
                            <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-50 w-64 p-2 shadow-md">
                                <li>
                                    <a :href="route('dashboard')" class="flex items-center gap-2">
                                        <HomeIcon class="w-5 h-5" />
                                        Панель управления
                                    </a>
                                </li>
                                <li>
                                    <a :href="`/profile/${user.id}`" class="flex items-center gap-2">
                                        <UserIcon class="w-5 h-5" />
                                        Моя страница
                                    </a>
                                </li>
                                <li>
                                    <a :href="route('profile.show')" class="flex items-center gap-2">
                                        <Cog6ToothIcon class="w-5 h-5" />
                                        Настройки
                                    </a>
                                </li>
                                <li>
                                    <button @click="toggleTheme" class="flex items-center gap-2">
                                        <component :is="isDark ? SunIcon : MoonIcon" class="w-5 h-5" />
                                        Тема
                                    </button>
                                </li>
                                <li><hr class="my-1" /></li>
                                <li>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 text-error hover:bg-error/10"
                                        :disabled="isLoggingOut"
                                        @click="logout"
                                    >
                                        <ArrowRightOnRectangleIcon class="w-5 h-5" />
                                        {{ isLoggingOut ? 'Выход…' : 'Выход' }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </template>

                    <button
                        v-else
                        class="btn btn-primary btn-sm"
                        @click="actions.showAuthModal = true"
                    >
                        Войти
                    </button>
                </div>
            </div>
        </nav>
        <div
            v-if="isLoading"
            class="fixed inset-0 z-50 bg-base-300 bg-opacity-70 flex items-center justify-center pointer-events-none"
        >
            <span class="loading loading-bars loading-lg text-primary"></span>
        </div>
        <main class="flex-1">
            <slot/>
        </main>
        <Footer />
        <GlobalModals/>
        <AuthModal :show="actions.showAuthModal" @close="actions.showAuthModal = false" />

        <!-- Drawer для профиля -->
        <div class="drawer drawer-end z-50 lg:hidden">
            <input id="profile-drawer" type="checkbox" class="drawer-toggle" />
            <div class="drawer-side">
                <label for="profile-drawer" class="drawer-overlay"></label>
                <div class="menu bg-base-200 w-80 min-h-full p-4 space-y-2">
                    <div class="flex items-center gap-3 mb-4 pt-safe-t">
                        <label for="profile-drawer" class="btn btn-ghost btn-sm p-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none"
                                 viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </label>
                        <UserAvatar :src="user.profile_photo_url" :name="user.name" :size="40" />
                        <span class="font-semibold truncate">{{ user.name }}</span>
                    </div>
                    <Link :href="route('dashboard')" class="flex items-center gap-2 py-1">
                        <HomeIcon class="w-5 h-5" /> Панель управления
                    </Link>
                    <Link :href="`/profile/${user.id}`" class="flex items-center gap-2 py-1">
                        <UserIcon class="w-5 h-5" /> Моя страница
                    </Link>
                    <Link :href="route('profile.show')" class="flex items-center gap-2 py-1">
                        <Cog6ToothIcon class="w-5 h-5" /> Настройки
                    </Link>
                    <button @click="toggleTheme" class="flex items-center gap-2 py-1">
                        <component :is="isDark?SunIcon:MoonIcon" class="w-6 h-6"/> Тема
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 py-1 text-error"
                        :disabled="isLoggingOut"
                        @click="logout"
                    >
                        <ArrowRightOnRectangleIcon class="w-5 h-5" />
                        {{ isLoggingOut ? 'Выход…' : 'Выход' }}
                    </button>
                </div>
            </div>
        </div>
        <!-- Dock для мобильных -->
        <div class="dock fixed bottom-0 w-full bg-base-100 border-t border-base-300 z-40 lg:hidden">
            <Link :href="route('home')" class="flex flex-col items-center px-2"
                  :class="{ 'dock-active': route().current('home') }">
                <HomeIcon class="w-6 h-6" />
                <span class="dock-label text-xs">Главная</span>
            </Link>

            <Link :href="route('gallery.index')" class="flex flex-col items-center px-2"
                  :class="{ 'dock-active': route().current('gallery.index') }">
                <PhotoIcon class="w-6 h-6" />
                <span class="dock-label text-xs">Галерея</span>
            </Link>

            <Link :href="route('studio.index')" class="flex flex-col items-center px-2"
                  :class="{ 'dock-active': route().current('studio.index') }">
                <PlusCircleIcon class="w-6 h-6" />
                <span class="dock-label text-xs">Студия</span>
            </Link>

            <button @click="actions.showAuthModal = true" v-if="!isAuth"
                    class="flex flex-col items-center px-2">
                <ArrowRightOnRectangleIcon class="w-6 h-6" />
                <span class="dock-label text-xs">Войти</span>
            </button>

            <label v-if="isAuth" for="profile-drawer" class="flex flex-col items-center px-2 cursor-pointer"
                   :class="{
           'dock-active': ['dashboard', 'profile.show', 'profile'].includes(route().current()),
         }">
                <UserAvatar :src="user.profile_photo_url" :name="user.name" :size="24" />
                <span class="dock-label text-xs">Меню</span>
            </label>
        </div>

    </div>
</template>
