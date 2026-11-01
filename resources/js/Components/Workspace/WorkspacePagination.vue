<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
const props = defineProps({ pagination: { type: Object, required: true } })
const pages = computed(() => (props.pagination.links || []).filter(link => /^\d+$/.test(link.label) || link.label === '...'))
</script>

<template>
    <div class="workspace-pagination">
        <p class="ui-muted text-xs sm:text-sm">{{ pagination.from || 0 }}–{{ pagination.to || 0 }} из {{ pagination.total || 0 }}</p>
        <nav v-if="pagination.last_page > 1" class="flex flex-wrap items-center gap-1" aria-label="Страницы результатов">
            <Link v-if="pagination.prev_page_url" :href="pagination.prev_page_url" preserve-state preserve-scroll class="btn btn-sm btn-ghost" aria-label="Предыдущая страница">←</Link>
            <button v-else disabled class="btn btn-sm btn-ghost" aria-label="Предыдущая страница">←</button>
            <template v-for="(link, index) in pages" :key="index">
                <Link v-if="link.url" :href="link.url" preserve-state preserve-scroll :aria-current="link.active ? 'page' : undefined" :class="['btn btn-sm min-w-8', link.active ? 'btn-primary' : 'btn-ghost']">{{ link.label }}</Link>
                <span v-else class="px-2 ui-muted">{{ link.label }}</span>
            </template>
            <Link v-if="pagination.next_page_url" :href="pagination.next_page_url" preserve-state preserve-scroll class="btn btn-sm btn-ghost" aria-label="Следующая страница">→</Link>
            <button v-else disabled class="btn btn-sm btn-ghost" aria-label="Следующая страница">→</button>
        </nav>
    </div>
</template>
