<template>
    <div
        @click="go"
        class="relative w-48 flex-shrink-0
           bg-base-200 dark:bg-base-800
           rounded-lg overflow-hidden
           cursor-pointer group"
    >
        <!-- Превью -->
        <div
            class="relative w-full h-32
             bg-base-300 dark:bg-base-700
             grid"
            :class="thumbs.length === 1
               ? 'grid-cols-1'
               : thumbs.length === 2
                 ? 'grid-cols-3'
                 : 'grid-cols-3'"
        >
            <img
                v-for="(src,i) in thumbs"
                :key="i"
                :src="src"
                class="object-cover w-full h-full"
                :class="twoThumbs && i === 0 ? 'col-span-2' : ''"
            />
        </div>

        <!-- Текст с единообразными классами -->
        <div class="p-3 text-base-content">
            <h3 class="font-semibold truncate">{{ collection.name }}</h3>
            <p class="text-sm text-base-content/70">
                {{ collection.artworks_count }} работ
            </p>
            <p class="text-xs text-base-content/50">
                Создано: {{ formattedDate }}
            </p>
        </div>

        <!-- Кнопки редакт/удал -->
        <div
            v-if="isOwner"
            class="absolute top-2 right-2 flex space-x-2
             opacity-0 group-hover:opacity-100 transition-opacity"
            @click.stop
        >
            <button
                @click="edit"
                class="p-1 rounded-full
               bg-secondary text-secondary-content
               hover:bg-secondary-focus"
            >
                <PencilIcon class="w-4 h-4" />
            </button>
            <button
                @click="del"
                class="p-1 rounded-full
               bg-error text-error-content
               hover:bg-error-focus"
            >
                <TrashIcon class="w-4 h-4" />
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    collection: Object,
    isOwner:    Boolean
})
const emit = defineEmits(['edit','remove'])

const publicArts = computed(() => {
    return (props.collection.artworks || [])
        .filter(a => a.is_published && !a.is_private)
})
const thumbs = computed(() => {
    return publicArts.value
        .slice(0, 3)
        .map(a => a.thumb_url || a.media?.[0]?.original_url)
})
const twoThumbs = computed(() => thumbs.value.length === 2)
const formattedDate = computed(() =>
    new Date(props.collection.created_at)
        .toLocaleDateString()
)

function go()  { window.location.href = `/collections/${props.collection.id}` }
function edit() { emit('edit', props.collection) }
function del()  { emit('remove', props.collection) }
</script>

<style scoped>
.group:hover .group-hover\:opacity-100 {
    opacity: 1;
}
</style>
