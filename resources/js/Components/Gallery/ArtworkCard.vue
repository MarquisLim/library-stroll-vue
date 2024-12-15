<template>
    <div class="relative group mb-2 break-inside-avoid cursor-pointer" @click="goToArtwork">
        <img :src="art.media[0]?.original_url" class="w-full h-auto object-cover rounded" loading="lazy"/>

        <!-- Overlay при hover -->
        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-opacity"></div>

        <!-- Появляется только при hover -->
        <div class="absolute inset-0 flex flex-col justify-between p-2 opacity-0 group-hover:opacity-100 pointer-events-none">
            <div class="flex justify-between">
                <!-- Добавить в коллекцию -->
                <button @click.stop="openCollections($event)"
                        class="rounded-full bg-black bg-opacity-50 text-white w-8 h-8 flex items-center justify-center hover:bg-opacity-80 pointer-events-auto">
                    <img src="/images/icons/plus-btn.svg" alt="plus" class="w-5 h-5"/>
                </button>

                <!-- Лайк -->
                <button @click.stop="$emit('like',art)"
                        class="rounded-full w-8 h-8 flex items-center justify-center pointer-events-auto"
                        :class="art.liked_by_user?'bg-black bg-opacity-70':'bg-black bg-opacity-50 hover:bg-opacity-80 text-white'">
                    <img :src="art.liked_by_user?'/images/icons/liked.svg':'/images/icons/like.svg'" alt="like" class="w-5 h-5"/>
                </button>
            </div>

            <div class="flex justify-between items-center text-white pointer-events-none">
                <div class="flex items-center space-x-1">
                    <img :src="art.user.profile_photo_url" class="h-6 w-6 rounded-full object-cover"/>
                    <span class="text-sm">{{art.user.name}}</span>
                </div>
                <div class="text-sm">👁{{art.views_count||0}} | ❤️{{art.likes_count||0}}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { defineProps, defineEmits } from 'vue'

const props = defineProps({ art: Object })
const emit = defineEmits(['save', 'like'])

function goToArtwork() {
    window.location.href = `/artworks/${props.art.id}`
}

function openCollections(event) {
    emit('save', { art: props.art, event: event })
}
</script>
