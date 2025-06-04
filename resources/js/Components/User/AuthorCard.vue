<template>
    <Link :href="route('user.profile.show', author.id)" class="flex flex-col items-center text-center space-y-1">
        <div class="avatar">
            <div class="w-24 h-24 rounded-full ring ring-primary ring-offset-base-100 ring-offset-2 overflow-hidden">
                <img :src="author.profile_photo_url" alt="Avatar" />
            </div>
        </div>
        <span class="text-sm font-medium truncate w-24">{{ author.name }}</span>
    </Link>
</template>

<script setup>
import { Link} from '@inertiajs/vue3'
const props = defineProps({ author: Object })
</script>
