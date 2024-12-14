<template>
    <AppLayout :title="artwork.title || 'Artwork'">
        <div class="p-4 bg-gray-900 text-white min-h-screen flex flex-col md:flex-row gap-4">
            <div class="md:w-1/2">
                <img :src="artwork.media[0]?.original_url" class="w-full h-auto object-cover rounded" loading="lazy"/>
            </div>
            <div class="md:w-1/2 flex flex-col space-y-4">
                <h1 class="text-2xl font-bold">{{ artwork.title || 'Без названия' }}</h1>
                <p>{{artwork.description}}</p>
                <div class="flex items-center space-x-2">
                    <img class="h-8 w-8 rounded-full object-cover" :src="author.profile_photo_url" alt />
                    <span>{{ author.name }}</span>
                </div>
                <div class="flex items-center space-x-4">
                    <div>Лайков: {{artwork.likes_count}}</div>
                    <div>Просмотров: {{artwork.views_count}}</div>
                    <button class="btn btn-secondary" @click="openCollectionSelector">Добавить в коллекцию</button>
                    <button class="btn" @click="likeArtwork">Лайк</button>
                </div>

                <CommentsSection :artworkId="artwork.id"/>

            </div>
        </div>

        <CollectionSelector
            v-if="showCollectionSelectorFlag"
            :collections="collections"
            @close="showCollectionSelectorFlag=false"
            @selected="saveArtToCollection"
            @createCollection="showCreateCollectionModal=true"
        />

        <CreateCollectionModal
            v-if="showCreateCollectionModal"
            @close="showCreateCollectionModal=false"
            @created="collectionCreated"
        />
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import CommentsSection from '@/Components/Comments/CommentsSection.vue'
import CollectionSelector from '@/Components/Collections/CollectionSelector.vue'
import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import AppLayout from "@/Layouts/AppLayout.vue";

const page = usePage()
const artwork = page.props.artwork
const author = page.props.author
const collections = ref([...page.props.collections])

const showCollectionSelectorFlag=ref(false)
const showCreateCollectionModal=ref(false)

function openCollectionSelector(){
    showCollectionSelectorFlag.value=true
}

function saveArtToCollection(colId){
    axios.post(`/artworks/${artwork.id}/add-to-collection`,{collections:[colId]})
        .then(res=>{
            showCollectionSelectorFlag.value=false
        }).catch(err=>console.log(err))
}

function likeArtwork(){
    axios.post(`/artworks/${artwork.id}/like`)
        .then(res=>{
            artwork.likes_count=res.data.likes_count
        }).catch(err=>console.log(err))
}

function collectionCreated(col){
    collections.value.push(col)
    showCreateCollectionModal.value=false
}
</script>
