<template>
    <div class="modal modal-open">
        <div class="modal-box bg-white text-black">
            <h3 class="font-bold text-lg">Создать коллекцию</h3>
            <input v-model="name" type="text" placeholder="Name" class="w-full mt-2 px-2 py-1 rounded border"/>
            <label class="flex items-center space-x-2 mt-2">
                <input type="checkbox" v-model="isPrivate"/>
                <span>Приватная</span>
            </label>
            <div class="modal-action">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <button class="btn btn-primary" @click="createCollection" :disabled="!name.trim()">Создать</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import axios from 'axios'
const name = ref('')
const isPrivate = ref(false)

function createCollection(){
    axios.post('/studio/create-collection',{
        name:name.value,
        is_private:isPrivate.value
    }).then(res=>{
        emit('created',res.data.collection)
        emit('close')
    }).catch(err=>console.log(err))
}
</script>
