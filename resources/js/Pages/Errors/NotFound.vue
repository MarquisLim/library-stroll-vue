<template>
    <AppLayout title="404 — Не найдено">
        <div class="text-center py-16">
            <h1 class="text-6xl font-bold mb-4">404</h1>
            <p class="text-xl mb-6">Страница не найдена.</p>
            <Link href="/" class="btn btn-primary">На главную</Link>
        </div>
    </AppLayout>
</template>
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
</script>
