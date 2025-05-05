<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import axios from 'axios'
import { Inertia } from '@inertiajs/inertia'

// Принимаем заголовок страницы
const props = defineProps({ title: String })
const page = usePage()

// Состояние для мобильного меню (раскрытие/сворачивание)
const showingNavigationDropdown = ref(false)

// Функция для выхода из аккаунта
function logout() {
    Inertia.post(route('logout'))
}

// Проверяем, авторизован ли пользователь
const isAuth = computed(() => !!page.props.auth.user)
const user = computed(() => page.props.auth.user)

// Поле поиска и подсказки
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

// Обработка нажатия Enter в поле поиска
function onSearchKey(e) {
    if (e.key === 'Enter') {
        doSearch()
    }
}
</script>

<template>
    <div class="bg-gray-900 text-white min-h-screen flex flex-col">
        <Head :title="title" />

        <!-- Мобильный Navbar (для экранов меньше sm) -->
        <nav class="h-16 flex items-center justify-between px-4 border-b border-gray-700 sm:hidden">
            <!-- Левая сторона: кнопка гамбургера -->
            <div>
                <button
                    @click="showingNavigationDropdown = !showingNavigationDropdown"
                    class="p-2 rounded-md text-gray-400 hover:text-white hover:bg-gray-800 focus:outline-none"
                >
                    <img src="/images/icons/hamburger.svg" alt="menu" class="w-6 h-6" />
                </button>
            </div>
            <!-- Центр: логотип (уменьшенный) -->
            <div>
                <Link :href="route('gallery.index')" class="text-purple-400 font-bold text-2xl flex items-center">
                    <img src="/logo.png" alt="Лого" class="h-12" />
                </Link>
            </div>
            <!-- Правая сторона: аватар или кнопка "Войти" -->
            <div>
                <div v-if="isAuth">
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button class="focus:outline-none">
                                <img v-if="$page.props.jetstream.managesProfilePhotos && user"
                                     :src="user.profile_photo_url"
                                     alt="Profile"
                                     class="h-8 w-8 rounded-full object-cover" />
                                <span v-else class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium text-white bg-transparent rounded-md hover:bg-gray-800">
                  {{ user ? user.name : '' }}
                </span>
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="`/profile/${user.id}`">Профиль</DropdownLink>
                            <DropdownLink :href="route('profile.show')">Настройки</DropdownLink>
                            <div class="border-t border-gray-600"></div>
                            <form @submit.prevent="logout">
                                <DropdownLink as="button">Выход</DropdownLink>
                            </form>
                        </template>
                    </Dropdown>
                </div>
                <div v-else>
                    <Link href="/login" class="bg-white text-black px-4 py-2 rounded-full text-lg hover:bg-gray-200 transition-colors duration-200">
                        Войти
                    </Link>
                </div>
            </div>
        </nav>

        <!-- Desktop Navbar (для экранов от sm) -->
        <nav class="hidden sm:flex h-16 items-center px-4 border-b border-gray-700 relative">
            <!-- Левая секция (flex-1) -->
            <div class="flex flex-1 items-center">
                <Link :href="route('gallery.index')" class="text-purple-400 font-bold text-4xl flex items-center space-x-1">
                    <img src="/logo.png" alt="Лого" class="w-25 h-25" />
                </Link>
                <div class="flex items-center ml-6 space-x-4">
                    <Link :href="route('gallery.index')" class="text-white hover:text-purple-200 text-lg flex items-center space-x-1">
                        <img src="/images/icons/home.svg" alt="home" class="w-8 h-8" />
                    </Link>
                    <Link v-if="isAuth" :href="route('studio.index')" class="text-white hover:text-purple-200 text-lg flex items-center space-x-1">
                        <img src="/images/icons/plus-btn.svg" alt="create"  class="w-8 h-8" />
                    </Link>
                </div>
            </div>

            <!-- Центральная секция (flex-1) - Поиск по центру -->
            <div class="flex-1 justify-center relative">
                <input
                    v-model="searchQuery"
                    @input="loadSearchSuggestions(searchQuery)"
                    @keydown="onSearchKey"
                    type="text"
                    placeholder="Поиск..."
                    class="w-full px-4 py-2 pl-10 rounded-full bg-gray-700 text-white placeholder-gray-400 focus:outline-none"
                />
                <img src="/images/icons/search.svg" alt="search" class="absolute left-3 top-2 w-6 h-6" />
                <div v-if="showSuggestions" class="absolute left-0 right-0 bg-gray-700 mt-1 rounded p-2 max-h-40 overflow-auto z-50">
                    <div v-for="s in searchSuggestions" :key="s.id" class="p-1 hover:bg-gray-600 rounded cursor-pointer"
                         @click="searchQuery=s.name; doSearch()">
                        {{ s.name }}
                    </div>
                    <div v-if="searchSuggestions.length === 0" class="text-gray-400">
                        Рекомендуемые...
                    </div>
                </div>
            </div>

            <!-- Правая секция (flex-1) -->
            <div class="flex flex-1 items-center justify-end">
                <div v-if="isAuth">
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button class="focus:outline-none">
                                <img v-if="$page.props.jetstream.managesProfilePhotos && user"
                                     :src="user.profile_photo_url"
                                     alt="Profile"
                                     class="h-8 w-8 rounded-full object-cover" />
                                <span v-else class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium text-white bg-transparent rounded-md hover:bg-gray-800">
                  {{ user ? user.name : '' }}
                </span>
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="`/profile/${user.id}`">Профиль</DropdownLink>
                            <DropdownLink :href="route('profile.show')">Настройки</DropdownLink>
                            <div class="border-t border-gray-600"></div>
                            <form @submit.prevent="logout">
                                <DropdownLink as="button">Выход</DropdownLink>
                            </form>
                        </template>
                    </Dropdown>
                </div>
                <div v-else>
                    <Link href="/login" class="bg-white text-black px-4 py-2 rounded-full text-lg hover:bg-gray-200 transition-colors duration-200">
                        Войти
                    </Link>
                </div>
            </div>
        </nav>

        <!-- Мобильное меню (анимированное раскрытие под navbar) -->
        <div
            :class="[
        showingNavigationDropdown ? 'max-h-[500px] opacity-100' : 'max-h-0 opacity-0',
        'transition-all duration-300 sm:hidden bg-gray-800 overflow-hidden'
      ]"
        >
            <!-- Мобильный поиск -->
            <div class="px-4 py-2 border-b border-gray-700">
                <div class="relative">
                    <input
                        v-model="searchQuery"
                        @input="loadSearchSuggestions(searchQuery)"
                        @keydown="onSearchKey"
                        type="text"
                        placeholder="Поиск..."
                        class="w-full px-4 py-2 pl-10 rounded-full bg-gray-700 text-white placeholder-gray-400 focus:outline-none"
                    />
                    <img src="/images/icons/search.svg" alt="search" class="absolute left-3 top-2 w-6 h-6" />
                </div>
                <div v-if="showSuggestions" class="bg-gray-700 mt-1 rounded p-2 max-h-40 overflow-auto">
                    <div v-for="s in searchSuggestions" :key="s.id" class="p-1 hover:bg-gray-600 rounded cursor-pointer" @click="searchQuery = s.name; doSearch()">
                        {{ s.name }}
                    </div>
                    <div v-if="searchSuggestions.length === 0" class="text-gray-400">
                        Рекомендуемые...
                    </div>
                </div>
            </div>
            <Link :href="route('gallery.index')" class="block px-4 py-2 hover:bg-gray-700 text-lg">
                Home
            </Link>
            <Link :href="route('studio.index')" class="block px-4 py-2 hover:bg-gray-700 text-lg" v-if="isAuth">
                Create
            </Link>
        </div>

        <!-- Header (если передан слот header) -->
        <header v-if="$slots.header" class="bg-gray-800 shadow">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Основной контент страницы -->
        <main class="flex-1">
            <slot />
        </main>
        <GlobalModals />
    </div>
</template>

<style scoped>
body {
    background-color: #1a1a2e;
    padding-bottom: env(safe-area-inset-bottom);
    padding-top:    env(safe-area-inset-top);
    padding-left:   env(safe-area-inset-left);
    padding-right:  env(safe-area-inset-right);
    box-sizing: border-box;
}
</style>
