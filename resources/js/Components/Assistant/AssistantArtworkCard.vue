<script setup>
import { ArrowUpRightIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { Link } from '@inertiajs/vue3'

defineProps({ artwork: { type: Object, required: true } })
</script>

<template>
    <Link
        :href="artwork.url"
        class="group flex h-full items-start gap-3 rounded-2xl border border-base-300 bg-base-100 p-3.5 transition-colors hover:border-primary/45"
    >
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary" aria-hidden="true">
            <PhotoIcon class="h-5 w-5" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block break-words text-sm font-bold leading-snug [overflow-wrap:anywhere]">{{ artwork.title || 'Без названия' }}</span>
            <span class="mt-1 block text-xs font-semibold text-base-content/65 group-hover:text-primary">Открыть работу</span>
        </span>
        <ArrowUpRightIcon class="mt-1 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
    </Link>
</template>
