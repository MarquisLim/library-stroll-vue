<template>
    <AppLayout :title="artwork.title || 'Artwork'">
        <div class="max-w-7xl mx-auto p-4 bg-gray-900 text-white min-h-screen">
            <div class="flex items-center space-x-2 mb-4">
                <button class="btn bg-black bg-opacity-50 hover:bg-opacity-80 rounded-full" @click="goBack">
                    <img src="/images/icons/back.svg" alt="back" class="w-4 h-4"/>
                </button>
                <h1 class="text-2xl font-bold">{{ artwork.title || 'Без названия' }}</h1>
            </div>
            <div class="flex flex-col md:flex-row gap-4">
                <div class="md:w-1/2">
                    <img :src="artwork.media[0]?.original_url" class="w-full h-auto object-cover rounded" loading="lazy"/>
                </div>
                <div class="md:w-1/2 flex flex-col space-y-4">
                    <p>{{artwork.description}}</p>
                    <div class="flex items-center space-x-2">
                        <img class="h-8 w-8 rounded-full object-cover" :src="author.profile_photo_url" alt />
                        <span class="font-semibold">{{ author.name }}</span>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center space-x-1">
                            <button @click="likeArtwork" class="rounded-full w-8 h-8 flex items-center justify-center bg-black bg-opacity-50 hover:bg-opacity-80 text-white">
                                <img :src="artwork.liked_by_user?'/images/icons/liked.svg':'/images/icons/like.svg'" alt="like" class="w-5 h-5"/>
                            </button>
                            <span>{{artwork.likes_count}}</span>
                        </div>
                        <div class="flex items-center space-x-1">
                            <img src="/images/icons/comments.svg" alt="comments" class="w-5 h-5"/>
                            <span>{{artwork.comments_count||0}}</span>
                        </div>
                        <div class="flex items-center space-x-1">
                            <img src="/images/icons/views.svg" alt="views" class="w-5 h-5"/>
                            <span>{{artwork.views_count}}</span>
                        </div>
                        <button class="rounded-full bg-black bg-opacity-50 text-white w-8 h-8 flex items-center justify-center hover:bg-opacity-80"
                                @click="openCollectionSelector">
                            <img src="/images/icons/plus-btn.svg" alt="plus" class="w-5 h-5"/>
                        </button>
                    </div>

                    <CommentsSection :artworkId="artwork.id"/>
                </div>
            </div>

            <h2 class="text-xl font-bold mt-8 mb-2">Другие работы автора</h2>
            <div ref="authorWorksContainer" class="overflow-auto h-64 relative bg-gray-800 p-2"
                 @scroll.passive="loadMoreAuthorWorks">
                <div class="flex space-x-2">
                    <ArtworkCard v-for="aw in authorWorks" :key="aw.id" :art="aw" @save="openCollectionSelectorFromList(aw)" @like="likeArtFromList"/>
                </div>
                <div v-if="loadingAuthorWorks" class="text-center py-4">Загрузка...</div>
            </div>

            <CollectionSelector
                v-if="showCollectionSelectorFlag"
                :collections="collections"
                :position="dropdownPosition"
                :selected-collections="selectedArt.in_collections"
                @close="showCollectionSelectorFlag=false"
                @selected="saveArtToCollection"
                @createCollection="showCreateCollectionModal=true"
            />

            <CreateCollectionModal
                v-if="showCreateCollectionModal"
                @close="showCreateCollectionModal=false"
                @created="collectionCreated"
            />

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
import CommentsSection from '@/Components/Comments/CommentsSection.vue'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'

const page = usePage()
const artwork = page.props.artwork
const author = page.props.author
const collections = ref([...page.props.collections||[]])
const notificationMessage=ref(null)

artwork.liked_by_user=artwork.liked_by_user||false
artwork.in_collections=artwork.in_collections||[]

const showCollectionSelectorFlag=ref(false)
const showCreateCollectionModal=ref(false)
const selectedArt=ref(null)
const dropdownPosition=ref({top:0,left:0})

const authorWorks = ref([])
const authorWorksContainer = ref(null)
const loadingAuthorWorks = ref(false)
let authorPage=1

onMounted(()=>{
    loadAuthorWorks()
})

function loadAuthorWorks(){
    if(loadingAuthorWorks.value)return
    loadingAuthorWorks.value=true
    axios.get(`/author/${author.id}/works?page=${authorPage}`)
        .then(res=>{
            if(res.data.artworks && res.data.artworks.length>0){
                authorWorks.value.push(...res.data.artworks)
                authorPage++
            }
            loadingAuthorWorks.value=false
        }).catch(err=>{
        loadingAuthorWorks.value=false
        console.log(err)
    })
}

function loadMoreAuthorWorks(){
    const el=authorWorksContainer.value
    const bottom=el.scrollLeft+el.clientWidth>=el.scrollWidth-100
    if(bottom && !loadingAuthorWorks.value){
        loadAuthorWorks()
    }
}

function goBack(){
    window.history.back()
}

function likeArtwork(){
    axios.post(`/artworks/${artwork.id}/like`)
        .then(res=>{
            artwork.likes_count=res.data.likes_count
            artwork.liked_by_user=res.data.liked
            notificationMessage.value=res.data.liked?'Лайкнуто':'Лайк удален'
            setTimeout(()=>notificationMessage.value=null,3000)
        }).catch(err=>console.log(err))
}

function openCollectionSelector(e){
    selectedArt.value=artwork
    const rect=e.currentTarget.getBoundingClientRect()
    dropdownPosition.value={top:rect.bottom+window.scrollY,left:rect.left+window.scrollX}
    showCollectionSelectorFlag.value=true
}

function openCollectionSelectorFromList(awEvent){
    const {art,event}=awEvent
    selectedArt.value=art
    const rect=event.currentTarget.getBoundingClientRect()
    dropdownPosition.value={top:rect.bottom+window.scrollY,left:rect.left+window.scrollX}
    showCollectionSelectorFlag.value=true
}

function likeArtFromList(art){
    axios.post(`/artworks/${art.id}/like`)
        .then(res=>{
            const idx=authorWorks.value.findIndex(a=>a.id===art.id)
            if(idx>=0){
                authorWorks.value[idx].likes_count=res.data.likes_count
                authorWorks.value[idx].liked_by_user=res.data.liked
                notificationMessage.value=res.data.liked?'Лайкнуто':'Лайк удален'
                setTimeout(()=>notificationMessage.value=null,3000)
            }
        }).catch(err=>console.log(err))
}

function saveArtToCollection(colIds){
    axios.post(`/artworks/${selectedArt.value.id}/add-to-collection`,{collections:colIds})
        .then(res=>{
            showCollectionSelectorFlag.value=false
            notificationMessage.value='Добавлено в коллекцию'
            setTimeout(()=>{notificationMessage.value=null},3000)
            // обновим art.in_collections
            if(selectedArt.value.id===artwork.id){
                artwork.in_collections=res.data.in_collections
            } else {
                // если это из authorWorks или gallery
                let idx=authorWorks.value.findIndex(a=>a.id===selectedArt.value.id)
                if(idx>=0){
                    authorWorks.value[idx].in_collections=res.data.in_collections
                }
            }
        }).catch(err=>console.log(err))
}

function createCollection() {
    showCreateCollectionModal.value = true
}

function collectionCreated(col) {
    collections.value.push(col)
    showCreateCollectionModal.value = false
    notificationMessage.value = 'Коллекция создана'
    setTimeout(() => notificationMessage.value = null, 3000)
}
</script>
