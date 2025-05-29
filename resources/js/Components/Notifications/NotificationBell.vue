<script setup>
import { onMounted } from 'vue'
import { BellIcon }  from '@heroicons/vue/24/outline'
import { useNotifications } from '@/stores/useNotifications'
import NotifListDesktop  from './NotifListDesktop.vue'
import NotifDialogMobile from './NotifDialogMobile.vue'

const n = useNotifications()

onMounted(() => {
    n.initEcho()
    window.addEventListener('resize', () => (n.isMobile = window.innerWidth < 640))
})
</script>

<template>
    <div class="relative">
        <button class="p-2 rounded hover:bg-base-200 relative" @click.stop="n.show = !n.show">
            <BellIcon class="w-6 h-6" />
            <span v-if="n.unread" class="badge badge-xs badge-primary absolute -top-0.5 -right-0.5">
        {{ n.unread }}
      </span>
        </button>

        <!-- desktop -->
        <div v-if="n.show && !n.isMobile"
             v-click-outside="() => (n.show = false)"
             class="absolute right-0 mt-2 w-72 bg-base-100 shadow-lg rounded flex flex-col z-50">
            <NotifListDesktop />
        </div>

        <!-- mobile -->
        <Teleport to="body" v-if="n.show && n.isMobile">
            <NotifDialogMobile @close="n.show = false"/>
        </Teleport>
    </div>
</template>
