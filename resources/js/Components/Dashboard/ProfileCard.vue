<script setup>
import UserAvatar from '@/Components/Workspace/UserAvatar.vue'
import { Link, usePage } from '@inertiajs/vue3'

const user = usePage().props.auth.user
</script>

<template>
    <div class="ui-panel p-6 text-center space-y-5">
        <UserAvatar :src="user.profile_photo_url" :name="user.name" :size="80" class="mx-auto ring-4 ring-primary/10" />
        <h3 class="text-xl font-semibold text-base-content">{{ user.name }}</h3>
        <div class="flex flex-col space-y-2">
            <Link
                :href="`/profile/${user.id}`"
                class="btn btn-primary btn-block"
            >
                Моя страница
            </Link>
            <Link
                :href="route('profile.show')"
                class="btn btn-ghost btn-block border-base-300"
            >
                Настройки
            </Link>
        </div>
    </div>
</template>
