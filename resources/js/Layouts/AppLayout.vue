<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'

const props = defineProps({ title:String })
const page = usePage()
const showingNavigationDropdown = ref(false)

function logout(){
    router.post(route('logout'))
}

// Предположим, что page.props.notifications содержит массив уведомлений, unread_count - количество непрочитанных
// Если нет - добавьте сами в контроллер
const notifications = page.props.notifications || []
const unreadCount = notifications.filter(n=>!n.read_at).length

const showNotifications = ref(false)
function toggleNotifications(){
    showNotifications.value=!showNotifications.value
    // Если хотим отмечать прочитанными при открытии, сделаем запрос
    if(showNotifications.value){
        axios.post('/notifications/mark-all-read').then(()=>{
            // Обновляем unreadCount на фронте
        })
    }
}
</script>

<template>
    <div class="bg-gray-900 text-white min-h-screen flex flex-col">
        <Head :title="title" />

        <!-- Navbar -->
        <nav class="h-16 flex items-center px-4 border-b border-gray-700 relative">
            <!-- Hamburger (мобильный) -->
            <div class="-ml-2 mr-2 flex items-center sm:hidden">
                <button @click="showingNavigationDropdown=!showingNavigationDropdown"
                        class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-white hover:bg-gray-800 focus:outline-none">
                    <img src="/images/icons/hamburger.svg" alt="menu" class="w-5 h-5"/>
                </button>
            </div>
            <!-- Left side: Logo, Home, Create -->
            <div class="flex items-center space-x-4">
                <Link :href="route('gallery.index')" class="text-purple-400 font-bold text-xl flex items-center space-x-1">
                    <img src="/images/icons/home.svg" alt="home" class="w-5 h-5"/>
                    <span>L.S</span>
                </Link>
                <Link :href="route('gallery.index')" class="hidden sm:inline-block text-white hover:text-purple-200">
                    <img src="/images/icons/home.svg" alt="home" class="w-5 h-5 inline mr-1"/> Home
                </Link>
                <Link :href="route('studio.index')" class="hidden sm:inline-block text-white hover:text-purple-200">
                    <img src="/images/icons/plus-btn.svg" alt="create" class="w-5 h-5 inline mr-1"/> Создать
                </Link>
            </div>

            <!-- Center: Search -->
            <div class="flex-1 flex justify-center">
                <div class="relative w-1/2 max-w-sm hidden sm:block">
                    <input type="text" placeholder="Search..."
                           class="w-full px-4 py-2 rounded-full bg-gray-800 text-white placeholder-gray-400 focus:outline-none" />
                    <img src="/images/icons/search.svg" alt="search" class="absolute right-3 top-2 w-4 h-4 text-white"/>
                </div>
            </div>

            <!-- Right: notifications, profile -->
            <div class="flex items-center space-x-4">
                <!-- Notifications bell -->
                <div class="relative">
                    <button @click="toggleNotifications"
                            class="relative p-2 rounded-full hover:bg-gray-800 focus:outline-none">
                        <img src="/images/icons/bell.svg" alt="notifications" class="w-5 h-5 text-white"/>
                        <span v-if="unreadCount>0" class="absolute top-0 right-0 bg-red-500 text-white text-xs rounded-full px-1">{{unreadCount}}</span>
                    </button>
                    <div v-if="showNotifications" class="absolute right-0 mt-2 w-64 bg-gray-800 text-white p-2 rounded shadow z-50">
                        <h3 class="font-bold mb-2">Уведомления</h3>
                        <div v-if="notifications.length===0">Нет уведомлений</div>
                        <div v-for="n in notifications" :key="n.id" class="mb-2 bg-gray-700 p-2 rounded">
                            <div class="flex items-center space-x-2">
                                <img :src="n.data.user.profile_photo_url" class="h-6 w-6 rounded-full object-cover"/>
                                <span class="text-sm">{{n.data.user.name}}</span>
                                <span class="text-xs text-gray-300">{{timeAgo(n.created_at)}}</span>
                            </div>
                            <p class="text-sm mt-1">{{n.data.message}}</p>
                            <Link v-if="n.data.link" :href="n.data.link" class="text-blue-400 text-sm">Перейти</Link>
                        </div>
                    </div>
                </div>

                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button class="focus:outline-none">
                            <img v-if="$page.props.jetstream.managesProfilePhotos"
                                 :src="$page.props.auth.user.profile_photo_url"
                                 alt class="h-8 w-8 rounded-full object-cover">
                            <span v-else class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium text-white bg-transparent rounded-md hover:bg-gray-800">
                              {{ $page.props.auth.user.name }}
                            </span>
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="route('profile.show')">Профиль</DropdownLink>
                        <DropdownLink :href="route('profile.show')">Настройки</DropdownLink>
                        <div class="border-t border-gray-600" />
                        <form @submit.prevent="logout">
                            <DropdownLink as="button">Выход</DropdownLink>
                        </form>
                    </template>
                </Dropdown>
            </div>
        </nav>

        <!-- Responsive Navigation Menu -->
        <div :class="{'block': showingNavigationDropdown, 'hidden': ! showingNavigationDropdown}" class="sm:hidden bg-gray-800">
            <Link :href="route('gallery.index')" class="block px-4 py-2 hover:bg-gray-700">Home</Link>
            <Link :href="route('studio.index')" class="block px-4 py-2 hover:bg-gray-700">Создать</Link>
        </div>

        <!-- Header -->
        <header v-if="$slots.header" class="bg-gray-800 shadow">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main class="flex-1">
            <slot />
        </main>
    </div>
</template>

<script>
import axios from 'axios'
export default {
    methods:{
        timeAgo(dateStr){
            const date=new Date(dateStr)
            const diff= (Date.now()-date.getTime())/1000/60
            if(diff<60) return Math.floor(diff)+' мин назад'
            const hours=diff/60
            if(hours<24) return Math.floor(hours)+' ч назад'
            const days=hours/24
            return Math.floor(days)+' дн назад'
        }
    }
}
</script>
<style>
body {
    background-color: #1a1a2e; /* темный фон */
}
</style>
