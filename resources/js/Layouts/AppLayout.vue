<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import Dropdown       from '@/Components/Dropdown.vue'
import DropdownLink   from '@/Components/DropdownLink.vue'
import GlobalModals   from '@/Components/Common/GlobalModals.vue'
import AuthModal      from '@/Components/AuthModal.vue'
import axios          from 'axios'
import { Inertia }    from '@inertiajs/inertia'
import { useArtworkActions } from '@/stores/useArtworkActions'
import {
    HomeIcon, MagnifyingGlassIcon, PlusCircleIcon,
    SunIcon, MoonIcon, Bars3Icon
} from '@heroicons/vue/24/outline'
import { ChatBubbleLeftIcon } from '@heroicons/vue/24/outline'

import Echo             from 'laravel-echo'

/* ---------- props / pinia / page ---------- */
const props   = defineProps({ title:String })
const page    = usePage()
const actions = useArtworkActions()

const unreadCount = ref(page.props.unreadCount || 0)
const myId        = page.props.auth.user?.id

/* ---------- theme (light / dark) ---------- */
const isDark = ref(false)

onMounted(() => {          // init
    isDark.value = localStorage.getItem('theme') === 'dark'
    setHtmlTheme()

    if (!myId) return

    const convs = Array.isArray(page.props.conversationIds)
        ? page.props.conversationIds
        : []

    convs.forEach(id => {
        window.Echo
            .private(`conversation.${id}`)
            .listen('.MessageSent', ({ message }) => {
                if (message.user_id !== myId) {
                    unreadCount.value++
                }
            })
    })

    // 2) слушаем создание новых бесед: в них тоже могут сразу прилететь сообщения
    window.Echo
        .private(`user.${myId}`)
        .listen('.ConversationCreated', ({ conversation: conv }) => {
            // подписываемся на канал этой беседы
            window.Echo.private(`conversation.${conv.id}`)
                .listen('.MessageSent', ({ message }) => {
                    if (message.user_id !== myId) {
                        unreadCount.value++
                    }
                })
        })

    window.Echo
        .private(`user.${myId}`)
        .listen('.ConversationRead', ({ conversationId }) => {
            unreadCount.value = unreadCount.value
        })
})
watch(isDark, v=>{
    localStorage.setItem('theme', v ? 'dark' : 'light')
    setHtmlTheme()
})
function setHtmlTheme(){
    document.documentElement.setAttribute('data-theme', isDark.value ? 'dark' : 'light')
}
function toggleTheme(){ isDark.value = !isDark.value }

/* ---------- auth ---------- */
const isAuth = computed(()=>!!page.props.auth.user)
const user   = computed(()=>page.props.auth.user || {})
function logout(){ Inertia.post(route('logout')) }

/* ---------- search ---------- */
const searchQuery       = ref('')
const searchSuggestions = ref([])
const showSuggestions   = ref(false)
function loadSearchSuggestions(q){
    if(!q.trim()){ showSuggestions.value=false; return }
    axios.get('/search/suggestions',{params:{q}})
        .then(r=>{
            searchSuggestions.value = r.data.suggestions || []
            showSuggestions.value = searchSuggestions.value.length>0
        })
}
function doSearch(){
    if(!searchQuery.value.trim()) return
    Inertia.get('/search',{q:searchQuery.value})
    showSuggestions.value=false
}

/* ---------- mobile burger ---------- */
const showingNavigationMenu = ref(false)
</script>

<template>
    <div class="min-h-screen flex flex-col bg-base-100 text-base-content">

        <Head :title="title" />

        <!-- Navbar -->
        <!-- mobile nav -->
        <nav
            class="sm:hidden sticky top-0 z-40 bg-base-100/80 backdrop-blur
             flex items-center justify-between h-16 px-4 border-b border-base-300">

            <!-- burger -->
            <button @click="showingNavigationMenu = !showingNavigationMenu"
                    class="p-2 rounded-md hover:bg-base-200 transition">
                <Bars3Icon class="w-6 h-6"/>
            </button>

            <!-- logo -->
            <Link :href="route('gallery.index')" class="flex items-center">
                <img src="/logo.png" alt="logo" class="h-10"/>
            </Link>

            <!-- right -->
            <div class="flex items-center space-x-2">
                <button @click="toggleTheme"
                        class="p-2 rounded-md hover:bg-base-200 transition">
                    <component :is="isDark ? SunIcon : MoonIcon" class="w-6 h-6"/>
                </button>

                <template v-if="isAuth">
                    <Link
                        :href="route('messenger.index')"
                        class="relative p-2 hover:bg-base-200 rounded-md transition"
                    >
                        <ChatBubbleLeftIcon class="w-6 h-6"/>
                        <span
                            v-if="unreadCount > 0"
                            class="badge badge-sm badge-primary absolute top-0 right-0 translate-x-1/2 -translate-y-1/2"
                        >
                      {{ unreadCount }}
                    </span>
                    </Link>

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <img :src="user.profile_photo_url"
                                 class="w-8 h-8 rounded-full object-cover"/>
                        </template>
                        <template #content>
                            <DropdownLink :href="`/profile/${user.id}`">Профиль</DropdownLink>
                            <DropdownLink :href="route('profile.show')">Настройки</DropdownLink>
                            <div class="border-t border-base-300"/>
                            <form @submit.prevent="logout">
                                <DropdownLink as="button">Выход</DropdownLink>
                            </form>
                        </template>
                    </Dropdown>
                </template>
                <template v-else>
                    <button class="btn btn-primary"
                            @click="actions.showAuthModal = true">
                        Войти
                    </button>
                </template>
            </div>
        </nav>

        <!-- mobile dropdown -->
        <div v-show="showingNavigationMenu"
             class="sm:hidden border-b border-base-300 bg-base-200 px-4 py-2 space-y-2">

            <Link :href="route('gallery.index')"
                  class="flex items-center gap-2 py-2 hover:bg-base-300 rounded px-2">
                <HomeIcon class="w-6 h-6"/> <span>Галерея</span>
            </Link>

            <Link v-if="isAuth" :href="route('studio.index')"
                  class="flex items-center gap-2 py-2 hover:bg-base-300 rounded px-2">
                <PlusCircleIcon class="w-6 h-6"/> <span>Студия</span>
            </Link>

            <!-- search -->
            <div class="relative">
                <input v-model="searchQuery"
                       @input="loadSearchSuggestions(searchQuery)"
                       @keydown.enter="doSearch"
                       placeholder="Поиск…"
                       class="input input-bordered w-full"/>
                <ul v-if="showSuggestions"
                    class="absolute mt-1 w-full bg-base-200 border border-base-300
                   rounded shadow z-20 max-h-40 overflow-auto">
                    <li v-for="s in searchSuggestions" :key="s.id"
                        @click="searchQuery=s.name; doSearch()"
                        class="px-4 py-2 hover:bg-base-300 cursor-pointer">
                        {{ s.name }}
                    </li>
                </ul>
            </div>
        </div>

        <!-- desktop nav -->
        <nav
            class="hidden sm:flex sticky top-0 z-40 bg-base-100/80 backdrop-blur
             items-center justify-between h-16 px-8 border-b border-base-300">

            <!-- left block -->
            <div class="flex items-center space-x-8">
                <Link :href="route('home')" class="flex items-center">
                    <img src="/logo.png" class="h-12 w-auto"/>
                </Link>

                <Link :href="route('gallery.index')"
                      class="flex items-center gap-1 font-semibold hover:text-primary">
                    <HomeIcon class="w-6 h-6"/> <span>Галерея</span>
                </Link>

                <Link v-if="isAuth" :href="route('studio.index')"
                      class="flex items-center gap-1 font-semibold hover:text-primary">
                    <PlusCircleIcon class="w-6 h-6"/> <span>Студия</span>
                </Link>
            </div>

            <!-- Search -->
            <div class="relative flex-1 mx-8">
                <MagnifyingGlassIcon
                    class="absolute left-3 top-1/2 -translate-y-1/2 w-6 h-6 pointer-events-none"/>
                <input v-model="searchQuery"
                       @input="loadSearchSuggestions(searchQuery)"
                       @keydown.enter="doSearch"
                       placeholder="Поиск…"
                       class="input input-bordered w-full pl-10"/>
                <div v-if="showSuggestions"
                     class="absolute w-full mt-1 bg-base-200 border border-base-300 rounded shadow z-20 max-h-48 overflow-auto">
                    <div v-for="s in searchSuggestions" :key="s.id"
                         @click="searchQuery=s.name; doSearch()"
                         class="px-4 py-2 hover:bg-base-300 cursor-pointer">
                        {{ s.name }}
                    </div>
                </div>
            </div>

            <!-- right block -->
            <div class="flex items-center space-x-4">
                <button @click="toggleTheme" class="p-2 rounded-md hover:bg-base-200 transition">
                    <component :is="isDark ? SunIcon : MoonIcon" class="w-6 h-6"/>
                </button>
                <Link
                    :href="route('messenger.index')"
                    class="relative p-2 hover:bg-base-200 rounded-md transition"
                >
                    <ChatBubbleLeftIcon class="w-6 h-6"/>
                    <span
                        v-if="unreadCount > 0"
                        class="badge badge-sm badge-primary absolute top-0 right-0 translate-x-1/2 -translate-y-1/2"
                    >
                      {{ unreadCount }}
                    </span>
                </Link>
                <template v-if="isAuth">
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <img :src="user.profile_photo_url" class="w-8 h-8 rounded-full object-cover"/>
                        </template>
                        <template #content>
                            <DropdownLink :href="route('dashboard')">Панель управления</DropdownLink>
                            <DropdownLink :href="`/profile/${user.id}`">Моя страница</DropdownLink>
                            <DropdownLink :href="route('profile.show')">Настройки</DropdownLink>
                            <div class="border-t border-base-300"/>
                            <form @submit.prevent="logout">
                                <DropdownLink as="button">Выход</DropdownLink>
                            </form>
                        </template>
                    </Dropdown>
                </template>
                <template v-else>
                    <button class="btn btn-primary"
                            @click="actions.showAuthModal = true">
                        Войти
                    </button>
                </template>
            </div>
        </nav>
        <!-- /Navbar  -->

        <!-- Main -->
        <main class="flex-1 h-[calc(100vh-4rem)] relative">
            <slot/>
        </main>
        <!-- /Main -->

        <!-- Footer -->
        <footer class="bg-base-200 text-base-content border-t border-base-300">
            <div class="max-w-7xl mx-auto px-6 py-8 grid gap-6
                  sm:grid-cols-2 md:grid-cols-4">

                <div>
                    <h3 class="font-bold mb-2">Навигация</h3>
                    <ul class="space-y-1">
                        <li><Link class="link-hover" :href="route('gallery.index')">Галерея</Link></li>
                        <li v-if="isAuth">
                            <Link class="link-hover" :href="route('studio.index')">Студия</Link>
                        </li>
                        <li><Link class="link-hover" href="/about">О&nbsp;нас</Link></li>
                        <li><Link class="link-hover" href="/faq">FAQ</Link></li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-bold mb-2">Юридическое</h3>
                    <ul class="space-y-1">
                        <li><Link class="link-hover" :href="route('terms')">Польз. соглашение</Link></li>
                        <li><Link class="link-hover" :href="route('privacy')">Политика&nbsp;конфиденц.</Link></li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-bold mb-2">Контакты</h3>
                    <p class="text-sm">1333guesswho1333@gmail.com</p>
                    <p class="text-sm">+7&nbsp;705&nbsp;408-47-35</p>
                </div>

                <div class="sm:col-span-2 md:col-span-1 flex flex-col justify-between">
                    <p class="text-sm">
                        © 2025&nbsp;Aktan Shulenov<br>
                        Все права защищены
                    </p>
                    <div class="flex space-x-3 mt-4 md:mt-0">
                        <!-- dummy socials -->
                        <a href="#" class="link-hover">VK</a>
                        <a href="#" class="link-hover">TG</a>
                        <a href="#" class="link-hover">X</a>
                    </div>
                </div>
            </div>
        </footer>
        <!-- /Footer -->

        <GlobalModals/>
        <AuthModal v-if="actions.showAuthModal"
                   @close="actions.showAuthModal=false"/>
    </div>
</template>
