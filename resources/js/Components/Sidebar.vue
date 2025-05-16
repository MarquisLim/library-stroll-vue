<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import {
    HomeIcon,
    PaintBrushIcon,
    ShieldCheckIcon,
    ChevronDownIcon
} from '@heroicons/vue/24/solid'

const props = defineProps({ open: Boolean })
const emit  = defineEmits(['update:open'])

const open = ref(props.open)
watch(() => props.open, v => open.value = v)

const isDesktop = ref(window.innerWidth >= 768)
function onResize() { isDesktop.value = window.innerWidth >= 768 }
onMounted(() => window.addEventListener('resize', onResize))
onBeforeUnmount(() => window.removeEventListener('resize', onResize))

const page = usePage()
watch(() => page.url, () => {
    if (!isDesktop.value) emit('update:open', false)
})

function isActive(path) { return page.url.startsWith(path) }
function isSubActive(arr) { return arr.some(p => page.url.startsWith(p)) }
const isAdmin = page.props.auth.user?.roles?.some(r => r.toLowerCase() === 'admin')
</script>

<template>
    <!-- Оверлей на мобиле -->
    <div
        v-show="open && !isDesktop"
        class="fixed inset-0 bg-black/50 z-30"
        @click="$emit('update:open', false)"
    />

    <transition name="slide">
        <!-- Sticky для desktop, fixed для mobile, с собственным скроллом -->
        <nav
            v-show="open || isDesktop"
            class="fixed md:sticky inset-y-0 left-0 w-64
             bg-base-100 dark:bg-base-900 text-base-content
             transform duration-300 ease-in-out z-40
             top-0 md:top-16 h-full overflow-y-auto"
        >
            <div class="px-4 py-6 space-y-4">
                <Link
                    :href="route('dashboard')"
                    class="flex items-center px-3 py-2 rounded-lg"
                    :class="isActive('/dashboard')
            ? 'border-l-4 border-primary bg-base-200 dark:bg-base-800 text-base-content'
            : 'hover:bg-base-200 dark:hover:bg-base-800'"
                >
                    <HomeIcon class="w-5 h-5 mr-3"/> Панель управления
                </Link>

                <details class="group" :open="isSubActive(['/studio'])">
                    <summary
                        class="flex items-center px-3 py-2 rounded-lg cursor-pointer"
                        :class="isSubActive(['/studio'])
              ? 'border-l-4 border-primary bg-base-200 dark:bg-base-800 text-base-content'
              : 'hover:bg-base-200 dark:hover:bg-base-800'"
                    >
                        <PaintBrushIcon class="w-5 h-5 mr-3"/>
                        <span class="flex-1">Студия</span>
                        <ChevronDownIcon class="w-4 h-4 transition-transform group-open:rotate-180"/>
                    </summary>
                    <div class="pl-12 space-y-2 py-2 text-sm">
                        <Link :href="route('studio.manager')"
                              class="block px-3 py-2 rounded-lg"
                              :class="isActive('/studio/manager')
                    ? 'bg-base-200 dark:bg-base-800 text-base-content'
                    : 'hover:bg-base-200 dark:hover:bg-base-800'"
                        >Работы</Link>
                        <Link :href="route('studio.collections')"
                              class="block px-3 py-2 rounded-lg"
                              :class="isActive('/studio/collections')
                    ? 'bg-base-200 dark:bg-base-800 text-base-content'
                    : 'hover:bg-base-200 dark:hover:bg-base-800'"
                        >Коллекции</Link>
                    </div>
                </details>

                <details v-if="isAdmin" class="group" :open="isSubActive(['/admin'])">
                    <summary
                        class="flex items-center px-3 py-2 rounded-lg cursor-pointer"
                        :class="isSubActive(['/admin'])
              ? 'border-l-4 border-primary bg-base-200 dark:bg-base-800 text-base-content'
              : 'hover:bg-base-200 dark:hover:bg-base-800'"
                    >
                        <ShieldCheckIcon class="w-5 h-5 mr-3"/>
                        <span class="flex-1">Администрирование</span>
                        <ChevronDownIcon class="w-4 h-4 transition-transform group-open:rotate-180"/>
                    </summary>
                    <div class="pl-12 space-y-2 py-2 text-sm">
                        <Link :href="route('admin.users')"
                              class="block px-3 py-2 rounded-lg"
                              :class="isActive('/admin/users')
                    ? 'bg-base-200 dark:bg-base-800 text-base-content'
                    : 'hover:bg-base-200 dark:hover:bg-base-800'"
                        >Пользователи</Link>
                    </div>
                </details>
            </div>
        </nav>
    </transition>
</template>

<style>
.slide-enter-from { transform: translateX(-100%); }
.slide-enter-to   { transform: translateX(0); }
.slide-leave-from { transform: translateX(0); }
.slide-leave-to   { transform: translateX(-100%); }
.slide-enter-active,
.slide-leave-active { transition: transform .3s ease-in-out; }
</style>
