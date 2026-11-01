<script setup>
import { Link } from '@inertiajs/vue3';
import { PhotoIcon } from '@heroicons/vue/24/outline';
</script>

<template>
    <Link href="/" class="ui-brand text-xl" aria-label="LibraryStroll — главная">
        <span class="ui-brand-mark"><PhotoIcon class="h-5 w-5" /></span>
        <span>Library<span class="text-primary">Stroll</span></span>
    </Link>
</template>

