<script setup>
import { Head, Link, usePage, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import axios from 'axios'
import GlobalModals from '@/Components/Common/GlobalModals.vue'
import Footer from '@/Components/Footer.vue'
import AuthModal from '@/Components/AuthModal.vue'
import SearchOverlay from '@/Components/SearchOverlay.vue'
import NotificationBell from '@/Components/Notifications/NotificationBell.vue'
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

const props         = defineProps({ title: String })
const page          = usePage()
const searchModal = ref(null)
const actions       = useArtworkActions()
const isAuth        = computed(() => !!page.props.auth.user)
const user          = computed(() => page.props.auth.user || {})
const myId          = page.props.auth.user?.id
const isDark        = ref(false)
const isLoggingOut = ref(false)

const isMobile      = ref(window.innerWidth < 640)
const isLoading = ref(false)

router.on('start', () => (isLoading.value = true))
router.on('finish', () => (isLoading.value = false))

function resize() { isMobile.value = window.innerWidth < 640 }


// Messages
const subscribedConvs = new Set()
const msgUnread     = ref(page.props.unreadCount || 0)

// Theme
function setTheme() {
    document.documentElement.setAttribute('data-theme', isDark.value ? 'dark':'light')
}

watch(isDark, v => {
    localStorage.setItem('theme', v?'dark':'light')
    setTheme()
})

function subscribeToConversation(id) {
    if (!window.Echo || !myId || subscribedConvs.has(id)) return
    window.Echo.private(`conversation.${id}`)
        .listen('.MessageSent', ({ message }) => {
            if (message.user_id !== myId) msgUnread.value++
        })
    subscribedConvs.add(id)
}

onMounted(() => window.addEventListener('resize', resize))
onUnmounted(() => window.removeEventListener('resize', resize))

onMounted(() => {
    isDark.value = localStorage.getItem('theme') === 'dark'
    setTheme()

    page.props.conversationIds?.forEach(subscribeToConversation)

    if (window.Echo && myId) {
        window.Echo.private(`user.${myId}`)
            .listen('.ConversationCreated', ({ conversation }) => {
                subscribeToConversation(conversation.id)
                msgUnread.value++
            })
    }
})

watch(
    () => page.props.conversationIds,
    ids => ids?.forEach(subscribeToConversation)
)

// Search
const searchQ       = ref('')
const searchTab     = ref('all')
const suggestions   = ref([])
const showSugBox    = ref(false)

watch([searchQ, searchTab], async ([q,tab])=>{
    if(!q.trim()) return suggestions.value=[]
    const { data } = await axios.get(route('search.suggestions'), {
        params: { q, type: tab }
    })
    suggestions.value = data.suggestions
    showSugBox.value  = suggestions.value.length>0
})

function logout() {
    if (isLoggingOut.value) return
    isLoggingOut.value = true

    axios.post(route('logout'))
        .then(() => {
            actions.notify('Вы вышли из профиля', 'success')
            setTimeout(() => window.location.reload(), 800)
        })
        .catch(e => {
            if (e.response?.status === 419) window.location.reload()
        })
        .finally(() => {
            isLoggingOut.value = false
        })
}


</script>

<template>
    <div class="min-h-screen flex flex-col">
        <Head :title="title"/>
        <nav class="sticky top-0 bg-base-100 backdrop-blur z-40 pt-safe-t">
            <div class="relative flex items-center h-16 px-4 sm:px-8">
                <!-- Left -->
                <div class="flex items-center gap-4">

                    <!-- Logo -->
                    <Link :href="route('home')" class="hidden lg:block">
                        <img src="/logo.png?v=2" alt="logo" class="h-9 sm:h-10" />
                    </Link>

                    <!-- Навигация -->
                    <div class="hidden lg:flex ml-4 space-x-6">
                        <Link :href="route('gallery.index')" class="flex items-center gap-1 hover:underline">
                            <PhotoIcon class="w-5 h-5" /> Галерея
                        </Link>
                        <Link v-if="isAuth" :href="route('studio.index')" class="flex items-center gap-1 hover:underline">
                            <PlusCircleIcon class="w-5 h-5" /> Студия
                        </Link>
                    </div>
                </div>

                <!-- Center -->
                <div class="hidden lg:flex flex-1 justify-center px-2">
                    <div
                        class="w-full max-w-3xl bg-base-300 hover:bg-base-200 transition rounded-xl px-5 py-3 flex items-center gap-3 cursor-pointer shadow-sm"
                        @click="searchModal?.open()"
                    >
                        <MagnifyingGlassIcon class="w-6 h-6 text-base-content/80" />
                        <span class="text-base-content text-lg font-medium">Поиск...</span>
                    </div>
                </div>

                <SearchOverlay ref="searchModal" />
                <div class="block lg:hidden w-full pe-4 py-2">
                    <div
                        class="bg-base-300 hover:bg-base-200 transition rounded-xl px-5 py-3 flex items-center gap-3 cursor-pointer shadow-sm"
                        @click="searchModal?.open()"
                    >
                        <MagnifyingGlassIcon class="w-6 h-6 text-base-content/80" />
                        <span class="text-base-content text-base font-medium">Поиск...</span>
                    </div>
                </div>
                <!-- Right -->
                <div class="flex items-center ml-auto space-x-2">
                    <button @click="isDark=!isDark" class="p-2 rounded hover:bg-base-200">
                        <component :is="isDark?SunIcon:MoonIcon" class="w-6 h-6"/>
                    </button>

                    <NotificationBell />
                    <Link :href="route('messenger.index')" class="relative p-2 rounded hover:bg-base-200">
                        <ChatBubbleLeftIcon class="w-6 h-6"/>
                        <span v-if="msgUnread" class="badge badge-xs badge-primary absolute -top-0.5 -right-0.5">
                    {{ msgUnread }}
                </span>
                    </Link>

                    <template v-if="isAuth">
                        <div class="dropdown dropdown-end hidden lg:block">
                            <div tabindex="0" role="button">
                                <img :src="user.profile_photo_url" class="w-8 h-8 rounded-full object-cover" />
                            </div>
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
                                    <button @click="isDark = !isDark" class="flex items-center gap-2">
                                        <component :is="isDark ? SunIcon : MoonIcon" class="w-5 h-5" />
                                        Тема
                                    </button>
                                </li>
                                <li><hr class="my-1" /></li>
                                <li>
                                    <form @submit.prevent="logout">
                                        <button type="submit" class="flex items-center gap-2 text-error hover:bg-error/10">
                                            <ArrowRightOnRectangleIcon class="w-5 h-5" />
                                            Выход
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </template>

                    <button
                        v-else
                        class="btn btn-primary btn-sm hidden lg:inline-flex"
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
                        <img :src="user.profile_photo_url" class="w-10 h-10 rounded-full object-cover" />
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
                    <button @click="isDark=!isDark" class="flex items-center gap-2 py-1">
                        <component :is="isDark?SunIcon:MoonIcon" class="w-6 h-6"/> Тема
                    </button>
                    <form @submit.prevent="logout">
                        <button type="submit" class="flex items-center gap-2 text-error py-1">
                            <ArrowRightOnRectangleIcon class="w-5 h-5" /> Выход
                        </button>
                    </form>
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
                <img :src="user.profile_photo_url" class="w-6 h-6 rounded-full object-cover" />
                <span class="dock-label text-xs">Меню</span>
            </label>
        </div>

    </div>
</template>
