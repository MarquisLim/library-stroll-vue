<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'

// Принимаем props.title
const props = defineProps({
    title: String,
});

const page = usePage()
const showingNavigationDropdown = ref(false)

function logout(){
    router.post(route('logout'))
}
</script>

<template>
    <div class="bg-gray-900 text-white min-h-screen flex flex-col">
        <Head :title="title" />

        <!-- Navbar -->
        <nav class="h-16 flex items-center px-4 border-b border-gray-700">
            <!-- Left side: Logo, Home, Create -->
            <div class="flex items-center space-x-4">
                <!-- При нажатии на логотип -> галерея -->
                <Link :href="route('gallery.index')" class="text-purple-400 font-bold text-xl">L.S</Link>
                <Link :href="route('gallery.index')" class="hidden sm:inline-block text-white hover:text-purple-200">Home</Link>
                <Link :href="route('studio.index')" class="hidden sm:inline-block text-white hover:text-purple-200">Создать</Link>
            </div>

            <!-- Center: Search -->
            <div class="flex-1 flex justify-center">
                <div class="relative w-1/2 max-w-sm">
                    <input type="text" placeholder="Search..."
                           class="w-full px-4 py-2 rounded-full bg-gray-800 text-white placeholder-gray-400 focus:outline-none" />
                </div>
            </div>

            <!-- Right: Icons, Profile -->
            <div class="flex items-center space-x-4">
                <!-- Иконки сердечко, комментарии -->
                <Link :href="route('gallery.index')" class="hidden sm:inline-block text-white hover:text-purple-200">❤️</Link>
                <Link :href="route('gallery.index')" class="hidden sm:inline-block text-white hover:text-purple-200">💬</Link>

                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button class="focus:outline-none">
                            <img v-if="page.props.jetstream.managesProfilePhotos"
                                 :src="page.props.auth.user.profile_photo_url"
                                 alt class="h-8 w-8 rounded-full object-cover">
                            <span v-else class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium text-white bg-transparent rounded-md hover:bg-gray-800">
                {{ page.props.auth.user.name }}
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

<style>
body {
    background-color: #1a1a2e; /* темный фон */
}
</style>
