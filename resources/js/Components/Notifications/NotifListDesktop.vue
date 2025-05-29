<script setup>
import { useNotifications } from '@/stores/useNotifications'
import NotifItem from './NotifItem.vue'

const n = useNotifications()
</script>

<template>
    <div
        ref="n.box"
        class="overflow-auto max-h-96 flex-1 divide-y divide-base-300 dark:divide-base-700"
        @scroll="n.markVisible"
    >
        <NotifItem v-for="i in n.list" :key="i.id" :n="i" />
        <p v-if="!n.list.length" class="p-4 text-center opacity-60">Нет уведомлений</p>
    </div>

    <div class="p-2 space-y-2 bg-base-100">
        <button v-if="n.hasMore" class="btn btn-outline btn-sm w-full" @click.stop="n.load">
            Загрузить ещё
        </button>
        <button v-if="n.list.length" class="btn btn-link btn-sm w-full" @click.stop="n.markAll">
            Отметить всё
        </button>
    </div>
</template>
