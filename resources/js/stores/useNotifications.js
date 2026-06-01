// resources/js/stores/useNotifications.js
import { defineStore } from 'pinia'
import { ref, nextTick } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'

export const useNotifications = defineStore('notifications', () => {
    /* --- state --- */
    const list    = ref([])
    const unread  = ref(0)
    const page    = ref(1)
    const hasMore = ref(true)
    const show    = ref(false)
    const box     = ref(null)
    const isMobile = ref(window.innerWidth < 640)

    // флаг, чтобы не подписываться дважды
    const _subscribed = ref(false)

    /* --- helpers --- */
    function syncUnread() {
        unread.value = list.value.filter(n => !n.read_at).length
    }

    /* --- api --- */
    async function load() {
        if (!hasMore.value) return
        const { data } = await axios.get(`/notifications?page=${page.value}`)
        list.value.push(...data.data)
        hasMore.value = !!data.next_page_url
        page.value++
        syncUnread()
    }

    async function markRead(id) {
        const n = list.value.find(i => i.id === id)
        if (!n || n.read_at) return
        n.read_at = new Date().toISOString()
        syncUnread()
        axios.post(`/notifications/${id}/mark-read`)
    }

    async function markAll() {
        await axios.post('/notifications/mark-read')
        list.value.forEach(n => (n.read_at = new Date().toISOString()))
        unread.value = 0
    }

    function markVisible() {
        nextTick(() => {
            const el = box.value
            if (!el) return
            el.querySelectorAll('.notif-item').forEach(i => {
                const top = i.offsetTop - el.scrollTop
                const bottom = top + i.offsetHeight
                if (top >= 0 && bottom <= el.clientHeight) markRead(+i.dataset.id)
            })
        })
    }

    /** запускаем подписку по Echo один раз */
    function initEcho() {
        const pageData = usePage()
        const userId = pageData.props.auth.user?.id

        if (!userId || !window.Echo || _subscribed.value) {
            return
        }

        _subscribed.value = true
        load() // подгружаем первую страницу списка уведомлений

        window.Echo.private(`user.${userId}`)
            .notification(n => {
                // payload из BroadcastNotificationCreated
                list.value.unshift({
                    id:         n.id,
                    type:       n.type.split('\\').pop(),
                    data:       n.data,
                    created_at: n.created_at ?? new Date().toISOString(),
                    read_at:    n.read_at    ?? null,
                })
                syncUnread()
            })
    }

    return {
        /* state */
        list, unread, hasMore, show, box, isMobile,
        /* actions */
        load, markRead, markAll, markVisible, initEcho,
    }
})
