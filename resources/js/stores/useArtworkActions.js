// resources/js/stores/artworkActions.js
import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'

export const useArtworkActions = defineStore('artworkActions', () => {
    const collections     = ref([])
    const showSelector    = ref(false)
    const selectorPos     = ref({ top: 0, left: 0 })
    const selectedArt     = ref(null)

    // toast: { message, type, visible }
    const toast           = ref({ message: '', type: 'success', visible: false })

    const showCreateModal = ref(false)
    const page            = usePage()
    const showAuthModal   = ref(false)
    const pendingAction   = ref(null)

    let toastTimeout = null

    function notify(message, type = 'success') {
        if (toastTimeout) {
            clearTimeout(toastTimeout)
            toastTimeout = null
        }

        toast.value = { message, type, visible: true }

        toastTimeout = setTimeout(() => {
            toast.value.visible = false
            toastTimeout = null
        }, 3000)
    }

    function requireAuth(cb) {
        if (!page.props.auth?.user) {
            pendingAction.value = cb
            showAuthModal.value = true
            return false
        }
        return true
    }

    function setCollections(list) {
        collections.value = list
    }

    async function toggleLike(art) {
        if (!requireAuth(() => toggleLike(art))) return
        try {
            const { data } = await axios.post(`/artworks/${art.id}/like`)
            art.likes_count   = data.likes_count
            art.liked_by_user = data.liked
            notify(data.liked ? 'Лайкнуто' : 'Лайк удален', 'success')
        } catch {
            notify('Не удалось поставить лайк', 'error')
        }
    }

    function openSelector(art, rect) {
        if (!requireAuth(() => openSelector(art, rect))) return
        selectedArt.value = art
        selectorPos.value = {
            top: rect.bottom + window.scrollY,
            left: rect.left   + window.scrollX
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
        try {
            const { data } = await axios.post(
                `/artworks/${selectedArt.value.id}/add-to-collection`,
                { collections: ids }
            )
            selectedArt.value.in_collections = data.in_collections
            showSelector.value = false
            notify('Добавлено в коллекцию', 'success')
        } catch {
            notify('Ошибка при сохранении в коллекцию', 'error')
        }
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
        notify
    }
})
