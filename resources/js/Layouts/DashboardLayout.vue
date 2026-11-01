<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Sidebar from '@/Components/Sidebar.vue'
const sidebarOpen = ref(false)
const isMobile = ref(window.innerWidth < 1024)
const page = usePage()
const onResize = () => { isMobile.value = window.innerWidth < 1024; if (!isMobile.value) sidebarOpen.value = false }
let savedOverflow = null
function restoreScroll() {
    if (savedOverflow !== null) { document.body.style.overflow = savedOverflow; savedOverflow = null }
}
watch([sidebarOpen, isMobile], ([open, mobile]) => {
    if (open && mobile) {
        if (savedOverflow === null) savedOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
    } else restoreScroll()
})
watch(() => page.url, () => { sidebarOpen.value = false })
const onKeydown = event => { if (event.key === 'Escape') sidebarOpen.value = false }
onMounted(() => { window.addEventListener('resize', onResize); document.addEventListener('keydown', onKeydown) })
onBeforeUnmount(() => { window.removeEventListener('resize', onResize); document.removeEventListener('keydown', onKeydown); restoreScroll() })
</script>

<template>
    <AppLayout>
        <div class="workspace-shell">
            <Sidebar :open="sidebarOpen" @update:open="sidebarOpen = $event" />
            <div class="workspace-main">
                <button class="btn btn-sm mb-6 border-base-300 bg-base-100 lg:hidden" aria-controls="workspace-sidebar" :aria-expanded="sidebarOpen" @click="sidebarOpen = !sidebarOpen">
                    <i class="mdi mdi-menu text-lg" /> Разделы кабинета
                </button>
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
