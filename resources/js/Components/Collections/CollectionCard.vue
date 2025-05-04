<template>
    <div
        class="relative w-48 flex-shrink-0 bg-gray-800 rounded overflow-hidden cursor-pointer group"
        @click="go"
    >
        <!-- превью -->
        <div class="relative w-full h-32 bg-gray-700 grid"
             :class="thumbs.length === 1 ? 'grid-cols-1' : 'grid-cols-3'">
            <img
                v-for="(src,i) in thumbs"
                :key="i"
                :src="src"
                class="object-cover w-full h-full"
                :class="thumbs.length === 2 && i===0 ? 'col-span-2' : ''"
            />
        </div>

        <div class="p-2 text-white">
            <h3 class="font-bold truncate">{{ collection.name }}</h3>
            <p class="text-sm text-gray-400">{{ collection.artworks_count }} работ</p>
            <p class="text-xs text-gray-500">Создано: {{ new Date(collection.created_at).toLocaleDateString() }}</p>
        </div>

        <!-- кнопки только для автора -->
        <div
            v-if="isOwner"
            class="absolute top-2 right-2 flex space-x-2 opacity-0 group-hover:opacity-100 transition-opacity"
            @click.stop
        >
            <button class="bg-blue-600 p-1 rounded hover:bg-blue-500" @click="edit">✎</button>
            <button class="bg-red-600  p-1 rounded hover:bg-red-500"  @click="del">🗑</button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    collection: Object,
    isOwner:   { type: Boolean, default: false }  // <-- новое
})

const emit  = defineEmits(['edit','remove'])

/* до 3 картинок, но с учётом thumb_url */
const thumbs = computed(() =>
    props.collection.artworks.slice(0,3).map(a => a.thumb_url || a.media?.[0]?.original_url)
)

function go(){ window.location = `/collections/${props.collection.id}` }
function edit(e){ e.stopPropagation(); emit('edit', props.collection) }
function del (e){ e.stopPropagation(); emit('remove', props.collection) }
</script>

<style scoped>
.group:hover .group-hover\:opacity-100 {
    opacity: 1;
}
</style>
