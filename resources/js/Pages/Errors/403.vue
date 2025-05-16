<template>
    <div class="min-h-screen flex flex-col items-center justify-center bg-gray-900 text-white text-center p-6">
        <h1 class="text-6xl font-bold mb-4">403</h1>
        <p class="text-xl mb-6">У вас нет доступа к этой странице.</p>
        <Link href="/" class="btn btn-primary">Вернуться на главную</Link>
    </div>
</template>

<script setup>
    import { Link } from '@inertiajs/vue3'
</script>
