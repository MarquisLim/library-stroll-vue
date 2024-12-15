<template>
    <div class="mt-4 bg-gray-800 p-4 rounded">
        <h3 class="font-bold text-lg mb-2">Комментарии</h3>
        <div v-for="comment in comments" :key="comment.id" class="mt-2 bg-gray-700 p-2 rounded">
            <div class="flex items-center space-x-2">
                <img class="h-6 w-6 rounded-full object-cover" :src="comment.user.profile_photo_url" alt />
                <span class="font-semibold">{{comment.user.name}}</span>
                <span class="text-gray-400 text-xs">{{timeAgo(comment.created_at)}}</span>
            </div>
            <p class="ml-8">{{comment.text}}</p>
            <!-- replies -->
            <div v-for="reply in comment.replies" :key="reply.id" class="ml-8 mt-1 bg-gray-600 p-1 rounded">
                <div class="flex items-center space-x-2">
                    <img class="h-5 w-5 rounded-full object-cover" :src="reply.user.profile_photo_url" alt />
                    <span class="font-semibold text-sm">{{reply.user.name}}</span>
                    <span class="text-gray-300 text-xs">{{timeAgo(reply.created_at)}}</span>
                </div>
                <p class="ml-6 text-sm">{{reply.text}}</p>
            </div>
        </div>
        <div class="mt-4 flex space-x-2">
            <input v-model="newComment" type="text" placeholder="Написать комментарий..."
                   class="flex-1 px-2 py-1 rounded bg-gray-700 text-white border border-gray-600" />
            <button class="btn btn-primary" @click="postComment">Отправить</button>
        </div>
    </div>
</template>

<script setup>
import {ref,onMounted} from 'vue'
import axios from 'axios'
const props=defineProps({
    artworkId:Number
})
const comments=ref([])
const newComment=ref('')

onMounted(()=>{
    loadComments()
})

function loadComments(){
    axios.get(`/artworks/${props.artworkId}/comments`)
        .then(res=>{
            comments.value=res.data.comments
        }).catch(err=>console.log(err))
}

function postComment(){
    if(!newComment.value.trim())return
    axios.post(`/artworks/${props.artworkId}/comments`,{text:newComment.value})
        .then(res=>{
            comments.value.push(res.data.comment)
            newComment.value=''
        }).catch(err=>console.log(err))
}

function timeAgo(dateStr) {
    const date = new Date(dateStr)
    const diff = (Date.now() - date.getTime()) / 1000 / 60
    if (diff < 60) return Math.floor(diff) + ' мин назад'
    const hours = diff / 60
    if (hours < 24) return Math.floor(hours) + ' ч назад'
    const days = hours / 24
    return Math.floor(days) + ' дн назад'
}
</script>
