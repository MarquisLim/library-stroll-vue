<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { Inertia } from '@inertiajs/inertia'

const show    = ref(false)
const query   = ref('')
const results = ref([])


function open() {
    show.value   = true
    query.value  = ''
    results.value= []
}

function close() {
    show.value = false
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
    const { data } = await axios.post(route('messenger.conversations.store'), {
        user_ids: [user.id],
        type: 'dialog'
    })
    close()
    // переходим в созданный чат
    Inertia.visit(route('messenger.index', data.id))
}

defineExpose({ open, close })
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-base-100 p-6 rounded-lg w-80">
            <h3 class="text-lg font-semibold mb-4">Новый чат</h3>
            <input
                v-model="query"
                @input="onSearch"
                type="text"
                placeholder="Поиск по имени…"
                class="input input-bordered w-full mb-3"
            />
            <ul class="max-h-40 overflow-auto space-y-1">
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
            <div class="text-right mt-4">
                <button @click="close" class="btn btn-ghost">Отмена</button>
            </div>
        </div>
    </div>
</template>
