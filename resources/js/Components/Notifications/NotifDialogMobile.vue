<script setup>
import {ref, onMounted, onBeforeUnmount, computed} from 'vue'
import { useNotifications } from '@/stores/useNotifications'
import NotifItem from './NotifItem.vue'
import {usePage} from "@inertiajs/vue3";
const page = usePage()
const isAuth = computed(() => !!page.props.auth.user)

const emit = defineEmits(['close'])
const n = useNotifications()

const dialogRef = ref(null)

onMounted(() => {
    dialogRef.value?.showModal()
})

onBeforeUnmount(() => {
    if (dialogRef.value?.open) {
        dialogRef.value.close()
    }
})

function onScrollOrTouch() {
    n.markVisible()
}
</script>

<template>
    <dialog ref="dialogRef" class="modal" @click.self="emit('close')">
        <form method="dialog" class="modal-backdrop">
            <button style="display: none"></button>
        </form>

        <div class="modal-box max-w-xs w-full max-h-[80vh] p-0 flex flex-col" @click.stop>
            <header class="flex justify-between p-4 border-b">
                <span class="font-semibold">Уведомления</span>
                <button class="btn btn-ghost btn-circle btn-sm" @click="emit('close')">✕</button>
            </header>

            <div
                ref="n.box"
                class="overflow-auto flex-1 divide-y divide-base-300 dark:divide-base-700"
                @scroll="onScrollOrTouch"
                @touchmove="onScrollOrTouch"
            >
                <NotifItem
                    v-for="item in n.list"
                    :key="item.id"
                    :n="item"
                    mobile
                />
                <p v-if="!n.list.length" class="p-4 text-center opacity-60">
                    Нет уведомлений
                </p>
            </div>

            <footer class="p-2 space-y-2 bg-base-100" v-if="isAuth">
                <button
                    v-if="n.hasMore"
                    class="btn btn-outline btn-sm w-full"
                    @click.stop="n.load"
                >
                    Загрузить ещё
                </button>
                <button
                    v-if="n.list.length"
                    class="btn btn-link btn-sm w-full"
                    @click.stop="n.markAll"
                >
                    Отметить всё
                </button>
            </footer>
        </div>
    </dialog>
</template>
