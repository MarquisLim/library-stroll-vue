<!-- ProfileShow.vue -->
<template>
    <AppLayout :title="profileUser.name">
        <div class="p-4 bg-gray-900 text-white min-h-screen flex flex-col items-center">
            <img :src="profileUser.profile_photo_url" class="h-24 w-24 rounded-full object-cover"/>
            <h1 class="text-2xl font-bold mt-2">{{ profileUser.name }}</h1>
            <div class="flex space-x-2 mt-2">
                <Link :href="route('profile.update', profileUser.id)" class="text-white hover:text-purple-200">Настройки</Link>
                <button @click="showCollections=true" class="text-white hover:text-purple-200">Мои коллекции</button>
                <button @click="showMyWorks=true" class="text-white hover:text-purple-200">Мои работы</button>
                <button @click="showLiked=true" class="text-white hover:text-purple-200">Лайкнутые</button>
            </div>

            <div v-if="showCollections" class="mt-4">
                <h2 class="text-xl font-bold mb-2">Мои коллекции</h2>
                <div class="flex overflow-auto space-x-2 h-32">
                    <div v-for="col in collections" :key="col.id" class="bg-gray-800 px-4 py-2 rounded">
                        {{col.name}}
                    </div>
                </div>
            </div>

            <div v-if="showMyWorks" class="mt-4 w-full">
                <h2 class="text-xl font-bold mb-2">Мои работы</h2>
                <div class="columns-2 md:columns-3 lg:columns-4 gap-2">
                    <ArtworkCard v-for="art in artworks" :key="art.id" :art="art" @save="openCollectionSelector" @like="likeArt"/>
                </div>
            </div>

            <div v-if="showLiked" class="mt-4 w-full">
                <!-- Аналогично подгружаем лайкнутые работы -->
            </div>
        </div>

        <CollectionSelector
            v-if="showCollectionSelectorFlag"
            :collections="collections"
            :position="dropdownPosition"
            @close="showCollectionSelectorFlag=false"
            @selected="saveArtToCollection"
            @createCollection="createCollection"
        />

        <CreateCollectionModal
            v-if="showCreateCollectionModal"
            @close="showCreateCollectionModal=false"
            @created="collectionCreated"
        />

        <div v-if="notificationMessage" class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-black bg-opacity-80 text-white px-4 py-2 rounded">
            {{notificationMessage}}
        </div>
    </AppLayout>
</template>

<script setup>
import {ref} from 'vue'
import {usePage} from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'

const page=usePage()
const profileUser=page.props.profileUser
const collections=ref([...page.props.collections||[]])
const artworks=ref([...page.props.artworks||[]])

const showCollections=ref(false)
const showMyWorks=ref(false)
const showLiked=ref(false)

const showCollectionSelectorFlag=ref(false)
const showCreateCollectionModal=ref(false)
const selectedArt=ref(null)
const dropdownPosition=ref({top:0,left:0})
const notificationMessage=ref(null)

function openCollectionSelector({art,event}){
    selectedArt.value=art
    const rect = event.currentTarget.getBoundingClientRect()
    dropdownPosition.value={top:rect.bottom+window.scrollY,left:rect.left+window.scrollX}
    showCollectionSelectorFlag.value=true
}

function saveArtToCollection(colIds){
    axios.post(`/artworks/${selectedArt.value.id}/add-to-collection`,{collections:colIds})
        .then(res=>{
            showCollectionSelectorFlag.value=false
            notificationMessage.value='Добавлено в коллекцию'
            setTimeout(()=>notificationMessage.value=null,3000)
        }).catch(err=>console.log(err))
}

function createCollection(){
    showCreateCollectionModal.value=true
}

function collectionCreated(col){
    collections.value.push(col)
    showCreateCollectionModal.value=false
}

function likeArt(art){
    axios.post(`/artworks/${art.id}/like`)
        .then(res=>{
            const idx=artworks.value.findIndex(a=>a.id===art.id)
            if(idx>=0){
                artworks.value[idx].likes_count=res.data.likes_count
                artworks.value[idx].liked=!artworks.value[idx].liked
                notificationMessage.value=artworks.value[idx].liked?'Лайкнуто':'Лайк удален'
                setTimeout(()=>notificationMessage.value=null,3000)
            }
        }).catch(err=>console.log(err))
}
</script>
