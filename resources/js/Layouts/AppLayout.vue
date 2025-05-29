<script setup>
import { Head, Link, usePage, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import axios from 'axios'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import GlobalModals from '@/Components/Common/GlobalModals.vue'
import Footer from '@/Components/Footer.vue'
import AuthModal from '@/Components/AuthModal.vue'
import SearchOverlay from '@/Components/SearchOverlay.vue'
import NotificationBell from '@/Components/Notifications/NotificationBell.vue'
import {
    HomeIcon, PlusCircleIcon,
    SunIcon, MoonIcon, Bars3Icon,
    ChatBubbleLeftIcon,
    MagnifyingGlassIcon
} from '@heroicons/vue/24/outline'
import { useArtworkActions } from '@/stores/useArtworkActions'

const props         = defineProps({ title: String })
const page          = usePage()
const searchModal = ref(null)
const actions       = useArtworkActions()
const isAuth        = computed(() => !!page.props.auth.user)
const user          = computed(() => page.props.auth.user || {})
const myId          = page.props.auth.user?.id
const showMenu      = ref(false)
const isDark        = ref(false)

const isMobile      = ref(window.innerWidth < 640)
const isLoading = ref(false)

router.on('start', () => (isLoading.value = true))
router.on('finish', () => (isLoading.value = false))

function resize() { isMobile.value = window.innerWidth < 640 }


// Messages
const msgUnread     = ref(page.props.unreadCount || 0)

// Theme
function setTheme() {
    document.documentElement.setAttribute('data-theme', isDark.value ? 'dark':'light')
}

watch(isDark, v => {
    localStorage.setItem('theme', v?'dark':'light')
    setTheme()
})


onMounted(() => window.addEventListener('resize', resize))
onUnmounted(() => window.removeEventListener('resize', resize))

onMounted(() => {
    isDark.value = localStorage.getItem('theme') === 'dark'
    setTheme()

    if (!myId) return

    page.props.conversationIds?.forEach(id =>
        Echo.private(`conversation.${id}`)
            .listen('.MessageSent', ({ message }) => {
                if (message.user_id !== myId) msgUnread.value++
            })
    )
})

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

function selectSuggestion(s){
    router.get(route('search.index'), { q: s.name, type: searchTab.value })
    showSugBox.value = false
}
function onSearchEnter(){
    router.get(route('search.index'), { q: searchQ.value, type: searchTab.value })
    showSugBox.value = false
}

function logout() {
    axios.post(route('logout'))
        .then(() => window.location.reload())
        .catch(e => {
            if (e.response?.status === 419) window.location.reload()
        })
}

</script>

<template>
    <div class="min-h-screen flex flex-col">
        <Head :title="title"/>
        <nav class="sticky top-0 bg-base-100/80 backdrop-blur z-40 pt-safe-t">
            <div class="flex items-center h-16 px-4 sm:px-8">
                <button @click="showMenu=!showMenu" class="sm:hidden p-2 rounded hover:bg-base-200">
                    <Bars3Icon class="w-6 h-6"/>
                </button>
                <Link :href="route('home')" class="mx-auto sm:mx-0">
                    <img src="/logo.png" class="h-9 sm:h-12"/>
                </Link>
                <div class="hidden sm:flex ml-8 space-x-6">
                    <Link :href="route('gallery.index')" class="flex items-center gap-1">
                        <HomeIcon class="w-6 h-6"/> Галерея
                    </Link>
                    <Link v-if="isAuth" :href="route('studio.index')" class="flex items-center gap-1">
                        <PlusCircleIcon class="w-6 h-6"/> Студия
                    </Link>
                </div>

                <!-- Search -->
                <div class="hidden sm:block mx-auto w-1/2 px-4 relative">
                    <div
                        class="input input-bordered flex items-center px-3 py-2 cursor-pointer"
                        @click="searchModal?.open()"
                    >
                        <MagnifyingGlassIcon class="w-5 h-5 text-base-content/50 mr-2"/>
                        <span class="text-base-content/50">Поиск...</span>
                    </div>
                </div>
                <SearchOverlay ref="searchModal"/>
                <div class="flex items-center ml-auto space-x-2">
                    <button
                        class="sm:hidden p-2 rounded hover:bg-base-200"
                        @click="searchModal?.open()"
                    >
                        <MagnifyingGlassIcon class="w-6 h-6"/>
                    </button>
                    <button @click="isDark=!isDark" class="hidden sm:block p-2 rounded hover:bg-base-200">
                        <component :is="isDark?SunIcon:MoonIcon" class="w-6 h-6"/>
                    </button>

                    <!-- Notifications -->
                    <NotificationBell />

                    <!-- Messages -->
                    <Link :href="route('messenger.index')" class="relative p-2 rounded hover:bg-base-200">
                        <ChatBubbleLeftIcon class="w-6 h-6"/>
                        <span v-if="msgUnread" class="badge badge-xs badge-primary absolute -top-0.5 -right-0.5">
                          {{ msgUnread }}
                        </span>
                    </Link>

                    <!-- User dropdown / login -->
                    <template v-if="isAuth">
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <img :src="user.profile_photo_url" class="w-8 h-8 rounded-full object-cover"/>
                            </template>
                            <template #content>
                                <DropdownLink :href="route('dashboard')">Панель управления</DropdownLink>
                                <DropdownLink :href="`/profile/${user.id}`">Моя страница</DropdownLink>
                                <DropdownLink :href="route('profile.show')">Настройки</DropdownLink>
                                <div class="border-t my-2"></div>
                                <form @submit.prevent="logout">
                                    <DropdownLink as="button" type="submit">
                                        Выход
                                    </DropdownLink>
                                </form>
                            </template>
                        </Dropdown>
                    </template>
                    <button v-else class="btn btn-primary btn-sm" @click="actions.showAuthModal=true">Войти</button>
                </div>
            </div>

            <!-- Mobile menu -->
            <transition name="fade">
                <div v-show="showMenu" class="sm:hidden bg-base-200 px-4 py-3 space-y-2">
                    <Link :href="route('gallery.index')" class="flex items-center gap-2 py-2">
                        <HomeIcon class="w-6 h-6"/> Галерея
                    </Link>
                    <Link v-if="isAuth" :href="route('studio.index')" class="flex items-center gap-2 py-2">
                        <PlusCircleIcon class="w-6 h-6"/> Студия
                    </Link>
                    <button @click="isDark=!isDark" class="flex items-center gap-2 py-2">
                        <component :is="isDark?SunIcon:MoonIcon" class="w-6 h-6"/> Тема
                    </button>
                </div>
            </transition>
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
    </div>
</template>
