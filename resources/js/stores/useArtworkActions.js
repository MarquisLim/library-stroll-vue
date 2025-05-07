import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'

export const useArtworkActions = defineStore('artworkActions', () => {
    /* ------------ state ------------ */
    const collections               = ref([])

    const showSelector              = ref(false)
    const selectorPos               = ref({ top: 0, left: 0 })
    const selectedArt           = ref(null)

    const toast                 = ref(null)
    const showCreateModal           = ref(false)

    const page                          = usePage()
    const showAuthModal             = ref(false)
    const pendingAction         = ref(null)

    /* ------------ helpers ---------- */
    function notify(txt) {
        toast.value = txt
        setTimeout(() => (toast.value = null), 3_000)
    }

    function requireAuth(cb){
        if(!page.props.auth?.user){
            pendingAction.value = cb
            showAuthModal.value = true
            return false
        }
        return true
    }

    /* ------------ public actions ---- */
    function setCollections(list) {
        collections.value = list
    }

    async function toggleLike(art) {
        if (!requireAuth(() => toggleLike(art))) return
        return axios.post(`/artworks/${art.id}/like`).then(({data})=>{
            art.likes_count   = data.likes_count
            art.liked_by_user = data.liked
                notify(data.liked ? 'Лайкнуто' : 'Лайк удален')
                })
    }

    function openSelector(art, rect) {
        if (!requireAuth(() => openSelector(art, rect))) return
        selectedArt.value = art
        selectorPos.value = { top: rect.bottom + window.scrollY,
            left: rect.left   + window.scrollX }
        showSelector.value = true
    }

    function onAuthSuccess(){
        showAuthModal.value = false
        // page.props уже обновился после Inertia.reload
        if(pendingAction.value){
            pendingAction.value()         // повторяем, теперь авторизован
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
        /* state   */ collections, showAuthModal,
        showSelector, selectorPos, selectedArt,
        toast, showCreateModal,
        /* actions */ setCollections, toggleLike, openSelector,
        onAuthSuccess,
        saveToCollections, openCreateModal,
        addNewCollection, closeCreateModal,
    }
})
