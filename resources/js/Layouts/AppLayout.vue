<script setup>
import { Head, Link, usePage, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Inertia }    from '@inertiajs/inertia'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import GlobalModals from '@/Components/Common/GlobalModals.vue'
import Footer from '@/Components/Footer.vue'
import AuthModal from '@/Components/AuthModal.vue'
import SearchOverlay from '@/Components/SearchOverlay.vue'
import {
    HomeIcon, PlusCircleIcon,
    SunIcon, MoonIcon, Bars3Icon,
    BellIcon, ChatBubbleLeftIcon,
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

// Notifications
const notifList     = ref([])
const notifUnread   = ref(page.props.unreadNotificationsCount || 0)
const showNotif     = ref(false)
const isMobile = ref(window.innerWidth < 640)
function resize() { isMobile.value = window.innerWidth < 640 }

async function loadNotifs() {
    const { data } = await axios.get('/notifications')
    notifList.value   = data
    notifUnread.value = data.filter(n => !n.read_at).length
}

async function markAllRead() {
    await axios.post('/notifications/mark-read')
    notifList.value.forEach(n => n.read_at = new Date().toISOString())
    notifUnread.value = 0
}

function subjectLink(data) {
    const t = data.subject_type
    const id = data.subject_id

    if (t === 'artwork') {
        return route('artworks.show', id)
    }
    if (t === 'user') {
        return route('user.profile.show', id)
    }
    if (t === 'comment') {
        return route('artworks.show', data.parent_id) + `#comment-${id}`
    }

    return '#'
}

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
function toggleNotif() { showNotif.value = !showNotif.value }
onMounted(() => {
    isDark.value = localStorage.getItem('theme') === 'dark'
    setTheme()

    if (!myId) return

    loadNotifs()

    Echo.private(`user.${myId}`)
        .notification(n => {
            notifList.value.unshift({
                id:         n.id,
                type:       n.type.split('\\').pop(),
                data:       n.data,
                created_at: n.created_at ?? new Date().toISOString(),
                read_at:    n.read_at    ?? null,
            })
            notifUnread.value++
        })

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
    Inertia.post(route('logout'))
}
</script>

<template>
    <div class="min-h-screen flex flex-col">
        <Head :title="title"/>
        <nav class="sticky top-0 bg-base-100/80 backdrop-blur z-40 ">
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
                    <div class="relative">
                        <button @click="toggleNotif" class="p-2 rounded hover:bg-base-200 relative">
                            <BellIcon class="w-6 h-6"/>
                            <span v-if="notifUnread" class="badge badge-xs badge-primary absolute -top-0.5 -right-0.5">{{ notifUnread }}</span>
                        </button>

                        <!-- desktop dropdown -->
                        <div
                            v-if="showNotif && !isMobile"
                            @click.outside="showNotif=false"
                            class="absolute right-0 mt-2 w-72 max-h-96 bg-base-100 shadow-lg rounded overflow-y-auto z-50"
                        >
                            <div v-if="notifList.length" class="divide-y">
                                <div v-for="n in notifList" :key="n.id" class="p-3 hover:bg-base-200 flex">
                                    <img :src="n.data.avatar" class="w-8 h-8 rounded-full object-cover mr-3"/>
                                    <div class="flex-1 text-sm">
                                        <template v-if="n.type==='ArtworkLiked'">
                                            <Link :href="`/profile/${n.data.liker_id}`">{{n.data.liker_name}}</Link> лайкнул ваш арт
                                        </template>
                                        <template v-else-if="n.type==='CommentReceived'">
                                            <Link :href="`/profile/${n.data.commenter_id}`">{{n.data.commenter_name}}</Link> прокомментировал ваш арт
                                            <div class="italic text-xs mt-1">{{n.data.excerpt}}</div>
                                        </template>
                                        <template v-if="n.type === 'ComplaintCreated'">
                                            <Link :href="subjectLink(n.data)">
                                                {{ n.data.message }}
                                            </Link>
                                        </template>
                                        <template v-else-if="n.type==='ContentBlocked'">
                                            <span>{{ n.data.message }}</span>
                                            <div class="text-xs opacity-60">{{ n.data.note }}</div>
                                        </template>

                                        <template v-else-if="n.type==='ComplaintRejected'">
                                            <span>{{ n.data.message }}</span>
                                            <div class="text-xs opacity-60">{{ n.data.note }}</div>
                                        </template>
                                        <div class="text-xs opacity-60 mt-1">{{ new Date(n.created_at).toLocaleString() }}</div>
                                    </div>
                                    <span v-if="!n.read_at" class="w-2 h-2 bg-primary rounded-full self-center"/>
                                </div>
                            </div>
                            <div v-else class="p-4 text-center opacity-60">Нет уведомлений</div>
                            <button v-if="notifList.length" @click="markAllRead" class="w-full py-2 text-center btn-link">Отметить всё прочитанным</button>
                        </div>
                    </div>

                    <Teleport to="body">
                        <transition name="fade">
                            <div
                                v-if="showNotif && isMobile"
                                class="fixed inset-0 z-50 flex items-start justify-center p-4 bg-black/60"
                                @click.self="showNotif=false"
                            >
                                <div class="w-full max-w-sm bg-base-100 rounded-lg shadow-lg overflow-y-auto max-h-[80vh]">
                                    <div class="flex items-center justify-between p-4 border-b">
                                        <span class="font-semibold">Уведомления</span>
                                        <button class="btn btn-ghost btn-circle" @click="showNotif=false">✕</button>
                                    </div>
                                    <div v-if="notifList.length" class="divide-y">
                                        <div v-for="n in notifList" :key="n.id" class="p-3 hover:bg-base-200 flex">
                                            <img :src="n.data.avatar" class="w-8 h-8 rounded-full mr-3"/>
                                            <div class="flex-1 text-sm">
                                                <template v-if="n.type==='ArtworkLiked'">
                                                    <Link :href="`/profile/${n.data.liker_id}`">{{n.data.liker_name}}</Link> лайкнул ваш арт
                                                </template>
                                                <template v-else-if="n.type==='CommentReceived'">
                                                    <Link :href="`/profile/${n.data.commenter_id}`">{{n.data.commenter_name}}</Link> прокомментировал ваш арт
                                                    <div class="italic text-xs mt-1">{{n.data.excerpt}}</div>
                                                </template>
                                                <div class="text-xs opacity-60 mt-1">{{ new Date(n.created_at).toLocaleString() }}</div>
                                            </div>
                                            <span v-if="!n.read_at" class="w-2 h-2 bg-primary rounded-full self-center"/>
                                        </div>
                                    </div>
                                    <div v-else class="p-4 text-center opacity-60">Нет уведомлений</div>
                                    <button v-if="notifList.length" @click="markAllRead" class="w-full py-2 text-center btn-link">Отметить всё прочитанным</button>
                                </div>
                            </div>
                        </transition>
                    </Teleport>


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

        <main class="flex-1">
            <slot/>
        </main>
        <Footer />
        <GlobalModals/>
        <AuthModal v-if="actions.showAuthModal" @close="actions.showAuthModal=false"/>
    </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s }
.fade-enter-from,   .fade-leave-to   { opacity: 0 }
</style>
