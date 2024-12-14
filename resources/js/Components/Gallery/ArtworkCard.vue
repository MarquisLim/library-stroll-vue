<template>
    <div class="relative group mb-2 break-inside-avoid">
        <img :src="art.media[0]?.original_url" class="w-full h-auto object-cover rounded" loading="lazy"/>
        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition">
            <div class="flex flex-col items-center space-y-2">
                <button class="btn btn-secondary" @click="$emit('save')">Сохранить</button>
                <button class="btn" @click="$emit('like')">Лайк ({{art.likes_count||0}})</button>
            </div>
        </div>
        <div class="absolute bottom-1 left-1 text-sm text-white">
            Автор: {{art.user.name}}
        </div>
        <div class="absolute bottom-1 right-1 text-sm text-white">
            ❤️{{art.likes_count||0}} 👁{{art.views_count||0}}
        </div>
    </div>
</template>

<script setup>
const props=defineProps({
    art:Object
})
</script>
