// resources/js/Layouts/DashboardLayout.vue
<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import Sidebar   from '@/Components/Sidebar.vue'
import { Bars3Icon, XMarkIcon } from '@heroicons/vue/24/outline'

// State открытия сайдбара
const sidebarOpen = ref(window.innerWidth >= 768)

// Авто-toggle при ресайзе
function onResize() {
    sidebarOpen.value = window.innerWidth >= 768
}
onMounted(() => window.addEventListener('resize', onResize))
onBeforeUnmount(() => window.removeEventListener('resize', onResize))

// Отключаем скролл при открытом сайдбаре
watch(sidebarOpen, val => {
    document.body.style.overflow = val ? 'hidden' : ''
})
</script>

<template>
    <AppLayout @toggle-sidebar="sidebarOpen = !sidebarOpen">
        <div class="flex relative">
            <!-- Sidebar -->
            <Sidebar
                :open="sidebarOpen"
                @update:open="val => sidebarOpen = val"
            />

            <!-- Контент -->
            <main class="flex-1 overflow-auto p-4 bg-base-100 dark:bg-base-900">
                <!-- Кнопка-тогглер на мобиле: иконка + текст -->
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    class="md:hidden flex items-center px-2 py-1 mb-4 text-sm font-medium bg-primary text-white rounded"
                    aria-label="Toggle sidebar"
                >
                    <component :is="sidebarOpen ? XMarkIcon : Bars3Icon" class="w-5 h-5 mr-2" />
                    {{ sidebarOpen ? 'Закрыть меню' : 'Открыть меню' }}
                </button>

                <!-- Основной слот -->
                <slot/>
            </main>
        </div>
    </AppLayout>
</template>
