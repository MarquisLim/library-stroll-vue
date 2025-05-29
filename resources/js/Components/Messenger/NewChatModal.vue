<script setup>
import { ref, nextTick } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'

const dialogRef = ref(null)
const query   = ref('')
const results = ref([])

function open() {
    nextTick(() => dialogRef.value.showModal())
    query.value   = ''
    results.value = []
}

function close() {
    dialogRef.value.close()
}

async function onSearch() {
    if (!query.value.trim()) {
        results.value = []
        return
    }
    const { data } = await axios.get(route('users.search'), { params: { q: query.value } })
    results.value = data
}

async function startChat(user) {
    const { data } = await axios.post(
        route('messenger.conversations.store'),
        { user_ids: [user.id], type: 'dialog' }
    )
    close()
    router.visit(route('messenger.index', data.id))
}

defineExpose({ open, close })
</script>

<template>
    <!-- сам dialog, скрыт по умолчанию -->
    <dialog ref="dialogRef" class="modal">
        <div class="modal-box w-80 p-6">
            <h3 class="text-lg font-semibold mb-4">Новый чат</h3>

            <input
                v-model="query"
                @input="onSearch"
                type="text"
                placeholder="Поиск по имени…"
                class="input input-bordered w-full mb-3"
            />

            <ul class="max-h-40 overflow-auto space-y-1 mb-4">
                <li v-for="u in results" :key="u.id">
                    <button
                        @click="startChat(u)"
                        class="flex items-center w-full p-2 hover:bg-base-200 rounded"
                    >
                        <img :src="u.avatar" class="w-8 h-8 rounded-full mr-2"/>
                        <span>{{ u.name }}</span>
                    </button>
                </li>
            </ul>

            <div class="modal-action justify-end">
                <form method="dialog">
                    <button class="btn btn-error mr-2">Отмена</button>
                </form>
            </div>
        </div>

        <!-- фон, клик по которому тоже закроет dialog -->
        <form method="dialog" class="modal-backdrop">
            <button> </button>
        </form>
    </dialog>
</template>
