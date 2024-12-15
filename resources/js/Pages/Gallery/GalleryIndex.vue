<template>
    <AppLayout title="Галерея">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                Галерея
            </h2>
        </template>

        <!-- Контейнер со скроллом -->
        <div ref="scrollContainer" class="p-4 bg-gray-900 text-white min-h-screen overflow-auto relative" @scroll.passive="handleScroll">
            <div class="columns-1 sm:columns-2 md:columns-3 lg:columns-4 gap-2">
                <ArtworkCard
                    v-for="art in artworks"
                    :key="art.id"
                    :art="art"
                    @save="openCollectionSelector"
                    @like="likeArt"
                />
            </div>
            <div v-if="loading" class="text-center py-4">Загрузка...</div>

            <CollectionSelector
                v-if="showCollectionSelectorFlag"
                :collections="collections"
                :position="dropdownPosition"
                :selected-collections="selectedArt.in_collections"
                @close="showCollectionSelectorFlag=false"
                @selected="saveArtToCollection"
                @createCollection="createCollection"
            />

            <CreateCollectionModal
                v-if="showCreateCollectionModal"
                @close="showCreateCollectionModal=false"
                @created="collectionCreated"
            />

            <!-- Уведомления -->
            <div v-if="notificationMessage" class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-black bg-opacity-80 text-white px-4 py-2 rounded">
                {{notificationMessage}}
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import AppLayout from "@/Layouts/AppLayout.vue"
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'

const page = usePage()
const artworks = ref(Array.isArray(page.props.artworks)?[...page.props.artworks]:[])
const collections = ref(Array.isArray(page.props.collections)?[...page.props.collections]:[])

const loading = ref(false)
const pageNumber = ref(1)

const showCollectionSelectorFlag = ref(false)
const showCreateCollectionModal = ref(false)
const selectedArt = ref(null)
const dropdownPosition = ref({top:0,left:0})
const notificationMessage = ref(null)

const scrollContainer = ref(null)

function handleScroll(){
    const el = scrollContainer.value
    if(!el)return
    const bottom= el.scrollTop+el.clientHeight >= el.scrollHeight-100
    if(bottom && !loading.value){
        loadMore()
    }
}

function loadMore() {
    loading.value = true
    axios.get(`/gallery/load-more?page=${pageNumber.value+1}`)
        .then(res => {
            if(res.data.artworks && res.data.artworks.length>0){
                artworks.value.push(...res.data.artworks)
                pageNumber.value++
            }
            loading.value = false
        }).catch(err => {
        loading.value = false;
        console.log(err)
    })
}

function openCollectionSelector({art,event}){
    selectedArt.value=art
    const rect = event.currentTarget.getBoundingClientRect()
    dropdownPosition.value = { top: rect.bottom + window.scrollY, left: rect.left + window.scrollX }
    showCollectionSelectorFlag.value=true
}

function saveArtToCollection(colIds){
    axios.post(`/artworks/${selectedArt.value.id}/add-to-collection`, { collections: colIds })
        .then(res => {
            showCollectionSelectorFlag.value = false
            notificationMessage.value='Добавлено в коллекцию'
            setTimeout(()=>{notificationMessage.value=null},3000)
            // Обновить in_collections у selectedArt в artworks
            const idx=artworks.value.findIndex(a=>a.id===selectedArt.value.id)
            if(idx>=0){
                artworks.value[idx].in_collections=res.data.in_collections
            }
        }).catch(err => console.log(err))
}

function createCollection(){
    showCreateCollectionModal.value=true
}

function collectionCreated(col){
    collections.value.push(col)
    showCreateCollectionModal.value=false
    notificationMessage.value='Коллекция создана'
    setTimeout(()=>notificationMessage.value=null,3000)
}

function likeArt(art){
    axios.post(`/artworks/${art.id}/like`)
        .then(res=>{
            const idx=artworks.value.findIndex(a=>a.id===art.id)
            if(idx>=0){
                artworks.value[idx].likes_count=res.data.likes_count
                artworks.value[idx].liked_by_user=res.data.liked
                notificationMessage.value=res.data.liked?'Лайкнуто':'Лайк удален'
                setTimeout(()=> {
                    notificationMessage.value = null
                }, 3000)
            }
        }).catch(err => {
        console.log(err)
    })
}

onMounted(() => {
    // При монтировании если нужно что-то проверить
})
</script>
