// resources/js/stores/useArtworkActions.js

import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'

export const useArtworkActions = defineStore('artworkActions', () => {
    /* state */
    const collections       = ref([])
    const showSelector      = ref(false)
    const selectorPos       = ref({ top: 0, left: 0 })
    const selectedArt       = ref(null)
    // toast как объект { visible, message, type }
    const toast             = ref({ visible: false, message: '', type: 'success' })
    const showCreateModal   = ref(false)
    const pageData          = usePage()
    const showAuthModal     = ref(false)
    const pendingAction     = ref(null)

    // Вспомогательная переменная для управления таймаутом
    let toastTimeout = null

    /* helpers */
    function notify(txt, type = 'success') {
        // Если уже висит таймаут, очищаем, чтобы новый показ был полноценным
        if (toastTimeout) {
            clearTimeout(toastTimeout)
        }
        toast.value = { visible: true, message: txt, type }
        toastTimeout = setTimeout(() => {
            toast.value.visible = false
            toastTimeout = null
        }, 3000)
    }

    function requireAuth(cb) {
        if (!pageData.props.auth?.user) {
            pendingAction.value = cb
            showAuthModal.value = true
            return false
        }
        return true
    }

    /* public actions */
    function setCollections(list) {
        collections.value = list
    }

    async function toggleLike(art) {
        if (!requireAuth(() => toggleLike(art))) return

        // сохраняем предыдущие значения, чтобы откатить при ошибке
        const prevCount  = art.likes_count
        const prevLiked  = art.liked_by_user

        // оптимистично переключаем локальное состояние
        art.liked_by_user = !prevLiked
        art.likes_count   = prevLiked ? prevCount - 1 : prevCount + 1
        notify(art.liked_by_user ? 'Лайкнуто' : 'Лайк удален', 'success')

        try {
            const { data } = await axios.post(`/artworks/${art.id}/like`)
            // синхронизируем с авторитетными данными с сервера
            art.likes_count   = data.likes_count
            art.liked_by_user = data.liked
        } catch (e) {
            // откатываем на предыдущие значения
            art.likes_count   = prevCount
            art.liked_by_user = prevLiked
            notify('Не удалось поставить/убрать лайк', 'error')
        }
    }

    function openSelector(art, rect) {
        if (!requireAuth(() => openSelector(art, rect))) return
        selectedArt.value = art
        selectorPos.value = {
            top: rect.bottom + window.scrollY,
            left: rect.left   + window.scrollX,
        }
        showSelector.value = true
    }

    function onAuthSuccess() {
        showAuthModal.value = false
        if (pendingAction.value) {
            pendingAction.value()
            pendingAction.value = null
        }
    }

    async function saveToCollections(ids) {
        const { data } = await axios.post(
            `/artworks/${selectedArt.value.id}/add-to-collection`,
            { collections: ids }
        )
        selectedArt.value.in_collections = data.in_collections
        showSelector.value = false
        notify('Обновление коллекции успешна', 'success')
    }

    function openCreateModal()  { showCreateModal.value = true }
    function closeCreateModal() { showCreateModal.value = false }

    function addNewCollection(col) {
        collections.value.push(col)
        notify('Коллекция создана', 'success')
        closeCreateModal()
    }

    return {
        collections,
        showAuthModal,
        showSelector,
        selectorPos,
        selectedArt,
        toast,
        showCreateModal,
        setCollections,
        toggleLike,
        openSelector,
        onAuthSuccess,
        requireAuth,
        saveToCollections,
        openCreateModal,
        addNewCollection,
        closeCreateModal,
        notify,
    }
})
