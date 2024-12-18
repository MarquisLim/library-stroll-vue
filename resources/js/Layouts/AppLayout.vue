<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import axios from 'axios'
import { Inertia } from '@inertiajs/inertia'

const props = defineProps({ title: String })
const page = usePage()

const showingNavigationDropdown = ref(false)

function logout() {
    Inertia.post(route('logout'))
}

// Определяем авторизован ли пользователь
const isAuth = computed(() => !!page.props.auth.user)
const user = computed(() => page.props.auth.user)

// Поиск
const searchQuery = ref('')
const searchSuggestions = ref([])
const showSuggestions = ref(false)

// Загрузка подсказок при вводе
function loadSearchSuggestions(query) {
    if (!query.trim()) {
        axios.get('/search/suggestions', { params: { recommended: true } })
            .then(res => {
                searchSuggestions.value = res.data.suggestions || []
                showSuggestions.value = searchSuggestions.value.length > 0
            })
            .catch(() => showSuggestions.value = false)
        return
    }
    axios.get('/search/suggestions', { params: { q: query } })
        .then(res => {
            searchSuggestions.value = res.data.suggestions || []
            showSuggestions.value = searchSuggestions.value.length > 0
        })
        .catch(() => {
            showSuggestions.value = false
        })
}

// Выполнить поиск
function doSearch() {
    if (!searchQuery.value.trim()) return
    Inertia.get('/search', { q: searchQuery.value })
    showSuggestions.value = false
}

// При нажатии Enter в поле поиска
function onSearchKey(e) {
    if (e.key === 'Enter') {
        doSearch()
    }
}

</script>

<template>
    <div class="bg-gray-900 text-white min-h-screen flex flex-col">
        <Head :title="title"/>

        <!-- Navbar -->
        <nav class="h-16 flex items-center px-4 border-b border-gray-700 relative sm:justify-between">
            <!-- Left: Hamburger (моб) -->
            <div class="flex items-center sm:hidden">
                <button @click="showingNavigationDropdown=!showingNavigationDropdown"
                        class="p-2 rounded-md text-gray-400 hover:text-white hover:bg-gray-800 focus:outline-none">
                    <img src="/images/icons/hamburger.svg" alt="menu" class="w-6 h-6"/>
                </button>
            </div>

            <!-- Center: Logo -->
            <div class="flex justify-ceentr sm:justify-start">
                <Link :href="route('gallery.index')"
                      class="text-purple-400 font-bold text-4xl flex items-center space-x-1">
                    <!--                    <span>L.S</span>logo.svg-->
                    <img src="/logo.svg" class="w-25 h-25"/>
                </Link>

                <!-- Home, Create на десктопе -->
                <div class="hidden sm:flex items-center ml-6 space-x-4">
                    <Link :href="route('gallery.index')"
                          class="text-white hover:text-purple-200 text-lg flex items-center space-x-1">
                        <img src="/images/icons/home.svg" alt="home" class="w-8 h-8"/>
                    </Link>
                    <Link v-if="isAuth" :href="route('studio.index')"
                          class="text-white hover:text-purple-200 text-lg flex items-center space-x-1">
                        <img src="/images/icons/plus-btn.svg" alt="create" class="w-8 h-8"/>
                    </Link>
                </div>
            </div>

            <!-- Поиск в центре navbar (только на десктопе) -->
            <div class="hidden sm:flex flex-1 justify-center mr-72">
                <div class="relative w-1/2 max-w-sm">
                    <input
                        v-model="searchQuery"
                        @input="loadSearchSuggestions(searchQuery)"
                        @keydown="onSearchKey"
                        type="text" placeholder="Поиск..."
                        class="w-full px-4 py-2 pl-10 rounded-full bg-gray-700 text-white placeholder-gray-400 focus:outline-none"
                    />
                    <img src="/images/icons/search.svg" alt="search" class="absolute left-3 top-2 w-6 h-6 text-white"/>
                    <!-- Подсказки -->
                    <div v-if="showSuggestions"
                         class="absolute left-0 right-0 bg-gray-700 mt-1 rounded p-2 max-h-40 overflow-auto z-50">
                        <div v-for="s in searchSuggestions" :key="s.id"
                             class="p-1 hover:bg-gray-600 rounded cursor-pointer"
                             @click="searchQuery=s.name; doSearch()">
                            {{ s.name }}
                        </div>
                        <div v-if="searchSuggestions.length===0" class="text-gray-400">Рекомендуемые...</div>
                    </div>
                </div>
            </div>

            <!-- Right side: Avatar or Login -->
            <div class="flex items-center space-x-4">
                <div v-if="isAuth">
                    <!-- Уведомления (при желании можно вернуть, аналогично предыдущему примеру) -->
                    <!-- Аватар и меню -->
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button class="focus:outline-none">
                                <img v-if="$page.props.jetstream.managesProfilePhotos && user"
                                     :src="user.profile_photo_url"
                                     alt="Profile" class="h-8 w-8 rounded-full object-cover">
                                <span v-else
                                      class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium text-white bg-transparent rounded-md hover:bg-gray-800">
                                  {{ user ? user.name : '' }}
                                </span>
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="`/profile/${user.id}`">Профиль</DropdownLink>
                            <DropdownLink :href="route('profile.show')">Настройки</DropdownLink>
                            <div class="border-t border-gray-600"/>
                            <form @submit.prevent="logout">
                                <DropdownLink as="button">Выход</DropdownLink>
                            </form>
                        </template>
                    </Dropdown>
                </div>
                <div v-else>
                    <Link href="/login" class="text-blue-400 hover:underline text-lg">Войти</Link>
                </div>
            </div>
        </nav>

        <!-- Mobile menu -->
        <div :class="{'block': showingNavigationDropdown, 'hidden': ! showingNavigationDropdown}"
             class="sm:hidden bg-gray-800">
            <!-- Поиск в мобильном -->
            <div class="px-4 py-2 border-b border-gray-700">
                <div class="relative">
                    <input
                        v-model="searchQuery"
                        @input="loadSearchSuggestions(searchQuery)"
                        @keydown="onSearchKey"
                        type="text" placeholder="Поиск..."
                        class="w-full px-4 py-2 pl-10 rounded-full bg-gray-700 text-white placeholder-gray-400 focus:outline-none"
                    />
                    <img src="/images/icons/search.svg" alt="search" class="absolute left-3 top-2 w-6 h-6 text-white"/>
                </div>
                <!-- Подсказки -->
                <div v-if="showSuggestions" class="bg-gray-700 mt-1 rounded p-2 max-h-40 overflow-auto">
                    <div v-for="s in searchSuggestions" :key="s.id" class="p-1 hover:bg-gray-600 rounded cursor-pointer"
                         @click="searchQuery=s.name; doSearch()">
                        {{ s.name }}
                    </div>
                    <div v-if="searchSuggestions.length===0" class="text-gray-400">Рекомендуемые...</div>
                </div>
            </div>
            <Link :href="route('gallery.index')" class="block px-4 py-2 hover:bg-gray-700 text-lg">Home</Link>
            <Link :href="route('studio.index')" class="block px-4 py-2 hover:bg-gray-700 text-lg" v-if="isAuth">Create
            </Link>
        </div>

        <!-- Header -->
        <header v-if="$slots.header" class="bg-gray-800 shadow">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                <slot name="header"/>
            </div>
        </header>

        <!-- Page Content -->
        <main class="flex-1">
            <slot/>
        </main>
    </div>
</template>

<style scoped>
body {
    background-color: #1a1a2e;
}
</style>
