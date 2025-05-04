import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'

export const useArtworkActions = defineStore('artworkActions', () => {
    /* ------------ state ------------ */
    const collections            = ref([])

    const showSelector           = ref(false)
    const selectorPos            = ref({ top: 0, left: 0 })
    const selectedArt            = ref(null)

    const toast                  = ref(null)
    const showCreateModal        = ref(false)

    /* ------------ helpers ---------- */
    function notify(txt) {
        toast.value = txt
        setTimeout(() => (toast.value = null), 3_000)
    }

    /* ------------ public actions ---- */
    function setCollections(list) {
        collections.value = list
    }

    async function toggleLike(art) {
        const { data } = await axios.post(`/artworks/${art.id}/like`)
        art.likes_count   = data.likes_count
        art.liked_by_user = data.liked
        notify(data.liked ? 'Лайкнуто' : 'Лайк удален')
    }

    function openSelector(art, rect) {
        selectedArt.value = art
        selectorPos.value = { top: rect.bottom + window.scrollY,
            left: rect.left   + window.scrollX }
        showSelector.value = true
    }

    async function saveToCollections(ids) {
        const { data } = await axios.post(
            `/artworks/${selectedArt.value.id}/add-to-collection`,
            { collections: ids }
        )
        selectedArt.value.in_collections = data.in_collections
        showSelector.value = false
        notify('Добавлено в коллекцию')
    }

    /* ----- создание коллекции ------- */
    function openCreateModal()  { showCreateModal.value = true }
    function closeCreateModal() { showCreateModal.value = false }

    function addNewCollection(col) {
        collections.value.push(col)      // реактивно добавили
        notify('Коллекция создана')
        closeCreateModal()
    }

    return {
        /* state   */ collections,
        showSelector, selectorPos, selectedArt,
        toast, showCreateModal,
        /* actions */ setCollections, toggleLike, openSelector,
        saveToCollections, openCreateModal,
        addNewCollection, closeCreateModal,
    }
})
