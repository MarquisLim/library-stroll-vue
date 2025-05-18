<template>
    <AppLayout title="403 — Доступ запрещен">
        <div class="text-center py-16">
            <h1 class="text-6xl font-bold mb-4">403</h1>
            <p class="text-xl mb-6">У вас нет прав для доступа к этой странице.</p>
            <Link href="/" class="btn btn-primary">На главную</Link>
        </div>
    </AppLayout>
</template>
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
</script>
