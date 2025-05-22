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
const isMobile      = ref(window.innerWidth < 640)
const notifPage     = ref(1)
const notifHasMore  = ref(true)
const notifBox = ref(null)
const isLoading = ref(false)

router.on('start', () => (isLoading.value = true))
router.on('finish', () => (isLoading.value = false))

function resize() { isMobile.value = window.innerWidth < 640 }

async function loadNotifs() {
    const { data } = await axios.get(`/notifications?page=${notifPage.value}`)
    notifList.value.push(...data.data)
    notifUnread.value = notifList.value.filter(n => !n.read_at).length
    notifHasMore.value = !!data.next_page_url
    notifPage.value++
    markVisibleNotifs()
}

async function markAllRead() {
    await axios.post('/notifications/mark-read')
    notifList.value.forEach(n => n.read_at = new Date().toISOString())
    notifUnread.value = 0
}

function markVisibleNotifs() {
    nextTick(() => {
        const box = notifBox.value
        if (!box) return
        box.querySelectorAll('.notif-item').forEach(el => {
            if (!el.classList.contains('read')) {
                const top    = el.offsetTop  - box.scrollTop
                const bottom = top + el.offsetHeight
                if (top >= 0 && bottom <= box.clientHeight) {
                    el.classList.add('read')
                    const notif = notifList.value.find(n => n.id == el.dataset.id)
                    if (notif && !notif.read_at) {
                        notif.read_at = new Date().toISOString()
                        notifUnread.value--
                        axios.post(`/notifications/${notif.id}/mark-read`)
                    }
                }
            }
        })
    })
}

window.addEventListener('scroll', () => {
    if (showNotif.value) markVisibleNotifs()
})

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
// helper, чтобы не повторяться в шаблоне
function notifInfo(n){
    const t = n.type.split('\\').pop();
    const d = n.data;

    switch (t){
        case 'ArtworkLiked':
            return {
                html:
                    `<a class="link no-underline text-blue-500" href="/profile/${d.liker_id}">${d.liker_name}</a>
                     лайкнул ваш арт
                     <a class="link no-underline text-blue-500 ml-1" href="${d.artwork_url}">«${d.artwork_title}»</a>`,
            };

        case 'CommentReceived':
            return {
                html:
                    `<a class="link no-underline text-blue-500" href="/profile/${d.commenter_id}">${d.commenter_name}</a>
                        прокомментировал ваш
                     <a class="link no-underline text-blue-500 ml-1" href="${d.artwork_url}">«${d.artwork_title}»</a>`,
                extra: d.excerpt
            };

        case 'ComplaintCreated':
            return {
                html:
                    `Жалоба от <a class="link no-underline text-blue-500" href="/profile/${d.user_id}">${d.user_name}</a>
                     на <a class="link no-underline text-blue-500" href="${d.subject_url}">«${d.subject_title}»</a>`
            };

        case 'ContentBlocked':
            return {
                html:
                    `${d.message}
                     <a class="link no-underline text-blue-500 ml-1" href="${d.url}">«${d.title}»</a>`,
                extra: d.note
            };

        case 'ComplaintRejected':
            return {
                html:
                    `Ваша жалоба на
                     <a class="link no-underline text-blue-500" href="${d.url}">«${d.title}»</a> отклонена`,
                extra: d.note
            };

        /*  если добавите ComplaintApproved — обрабатывайте тут же  */

        default:
            return { text:'Уведомление', url:'#' };
    }
}
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
                            ref="notifBox"
                            @scroll="markVisibleNotifs"
                        >
                            <div v-if="notifList.length" class="divide-y">
                                <div v-for="n in notifList" :key="n.id" class="p-3 hover:bg-base-200 flex notif-item" :class="{ read: n.read_at }" :data-id="n.id">
                                    <img :src="n.data.avatar" class="w-8 h-8 rounded-full mr-3"/>

                                    <div class="flex-1 text-sm">
                                        <!-- основной текст -->
                                        <span v-html="notifInfo(n).html"></span>

                                        <!-- доп-строка -->
                                        <div v-if="notifInfo(n).extra" class="text-xs italic opacity-70 mt-1">
                                            {{ notifInfo(n).extra }}
                                        </div>

                                        <div class="text-xs opacity-60 mt-1">
                                            {{ new Date(n.created_at).toLocaleString() }}
                                        </div>
                                    </div>

                                    <span v-if="!n.read_at" class="w-2 h-2 bg-primary rounded-full self-center"/>
                                </div>
                            </div>
                            <div v-else class="p-4 text-center opacity-60">Нет уведомлений</div>
                            <div class="p-3">
                                <button
                                    v-if="notifHasMore"
                                    @click.stop="loadNotifs"
                                    class="btn btn-outline w-full mt-2"
                                >
                                    Загрузить ещё
                                </button>
                            </div>
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
                                <div class="w-full max-w-sm bg-base-100 rounded-lg shadow-lg overflow-y-auto max-h-[80vh]"
                                     ref="notifBox"
                                     @scroll="markVisibleNotifs">
                                    <div class="flex items-center justify-between p-4 border-b">
                                        <span class="font-semibold">Уведомления</span>
                                        <button class="btn btn-ghost btn-circle" @click="showNotif=false">✕</button>
                                    </div>
                                    <div v-if="notifList.length" class="divide-y">
                                        <div v-for="n in notifList"
                                             :key="n.id"
                                             class="p-3 hover:bg-base-200 flex"
                                             :class="{ read: n.read_at }"
                                             :data-id="n.id"
                                        >
                                            <img :src="n.data.avatar" class="w-8 h-8 rounded-full mr-3"/>

                                            <div class="flex-1 text-sm">
                                                <!-- основной текст -->
                                                <span v-html="notifInfo(n).html"></span>

                                                <!-- доп-строка -->
                                                <div v-if="notifInfo(n).extra" class="text-xs italic opacity-70 mt-1">
                                                    {{ notifInfo(n).extra }}
                                                </div>

                                                <div class="text-xs opacity-60 mt-1">
                                                    {{ new Date(n.created_at).toLocaleString() }}
                                                </div>
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
        <AuthModal v-if="actions.showAuthModal" @close="actions.showAuthModal=false"/>
    </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s }
.fade-enter-from,   .fade-leave-to   { opacity: 0 }
.notif-item.read { opacity: 0.9; transition: opacity .4s ease; }
</style>
