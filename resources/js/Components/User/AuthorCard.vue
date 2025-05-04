<template>
    <Link :href="`/profile/${author.id}`"
          class="flex flex-col items-center text-center bg-gray-800 p-3 rounded
               hover:bg-gray-700 transition">
        <img :src="author.profile_photo_url"
             class="w-24 h-24 rounded-full object-cover mb-2 sm:w-20 sm:h-20">
        <span class="text-sm truncate w-full">{{ author.name }}</span>
    </Link>
</template>

<script setup>
const props = defineProps({ author:Object })
</script>
