<template>
    <div class="mt-4">
        <h3 class="font-bold text-lg">Комментарии</h3>
        <div v-for="comment in comments" :key="comment.id" class="mt-2">
            <div class="flex items-center space-x-2">
                <img class="h-6 w-6 rounded-full object-cover" :src="comment.user.profile_photo_url" alt />
                <span class="font-semibold">{{comment.user.name}}</span>
            </div>
            <p class="ml-8">{{comment.text}}</p>
            <button class="text-sm text-blue-400 ml-8" @click="replyToComment(comment.id)">Ответить</button>
            <div v-for="reply in comment.replies" :key="reply.id" class="ml-12 mt-1 text-sm">
                <div class="flex items-center space-x-2">
                    <img class="h-5 w-5 rounded-full object-cover" :src="reply.user.profile_photo_url" alt />
                    <span class="font-semibold">{{reply.user.name}}</span>
                </div>
                <p class="ml-6">{{reply.text}}</p>
            </div>
        </div>
        <div class="mt-4">
            <input v-model="newComment" type="text" placeholder="Написать комментарий..." class="w-full px-2 py-1 rounded" @keyup.enter="postComment"/>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
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

function replyToComment(commentId){
    // Логика ответа — можно открыть маленькое окошко для ответа, затем axios post на /comments/{id}/reply
}
</script>
