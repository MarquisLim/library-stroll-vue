<script setup>
import { Link, usePage } from '@inertiajs/vue3'

const user = usePage().props.auth.user
</script>

<template>
    <div class="bg-base-200 dark:bg-base-800 rounded-xl p-6 text-center shadow space-y-4">
        <img
            :src="user.profile_photo_url"
            class="w-24 h-24 rounded-full object-cover mx-auto"
        />
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
                class="btn btn-secondary btn-block"
            >
                Настройки
            </Link>
        </div>
    </div>
</template>
