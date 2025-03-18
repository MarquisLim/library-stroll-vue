<template>
    <div
        class="relative w-48 flex-shrink-0 bg-gray-800 rounded overflow-hidden cursor-pointer group"
        @click="goToCollection"
    >
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

        <!-- Кнопки "Редактировать" и "Удалить", видны только при hover -->
        <div
            class="absolute top-2 right-2 flex space-x-2 opacity-0 group-hover:opacity-100 transition-opacity"
            @click.stop
        >
            <!-- Редактировать -->
            <button
                class="bg-blue-600 p-1 rounded hover:bg-blue-500"
                @click="editCollection($event)"
            >
                ✎
            </button>

            <!-- Удалить -->
            <button
                class="bg-red-600 p-1 rounded hover:bg-red-500"
                @click="deleteCollection($event)"
            >
                🗑
            </button>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    collection: Object
})

const emit = defineEmits(['edit', 'remove'])

function goToCollection() {
    // Перейти на страницу коллекции
    window.location.href = `/collections/${props.collection.id}`
}

function editCollection(event) {
    // Останавливаем всплытие, чтобы не сработал переход
    event.stopPropagation()

    // Генерируем событие "edit", передаём всю инфу о коллекции
    emit('edit', props.collection)
}

function deleteCollection(event) {
    event.stopPropagation()
    emit('remove', props.collection)
}
</script>

<style scoped>
.group:hover .group-hover\:opacity-100 {
    opacity: 1;
}
</style>
