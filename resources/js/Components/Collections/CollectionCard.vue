<template>
    <div class="w-48 flex-shrink-0 bg-gray-800 rounded overflow-hidden cursor-pointer" @click="goToCollection">
        <div class="relative w-full h-32 bg-gray-700">
            <div class="absolute inset-0 flex">
                <!-- Превью до 3 изображений -->
                <img
                    v-for="(art, i) in collection.artworks.slice(0,3)"
                    :key="i"
                    :src="art.media[0]?.original_url"
                    class="w-1/3 h-full object-cover"
                />
            </div>
        </div>
        <div class="p-2 text-white">
            <h3 class="font-bold truncate">{{ collection.name }}</h3>
            <p class="text-sm text-gray-400">{{ collection.artworks_count }} работ</p>
            <p class="text-xs text-gray-500">Создано: {{ new Date(collection.created_at).toLocaleDateString() }}</p>
        </div>
    </div>
</template>

<script setup>
import { usePage } from '@inertiajs/vue3'

const props = defineProps({
    collection: Object
})

function goToCollection() {
    // Перейти на страницу коллекции
    // Например: /collections/{id}
    window.location.href = `/collections/${props.collection.id}`
}
</script>
