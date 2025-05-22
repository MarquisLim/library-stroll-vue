<script setup>
import {ref, computed, onMounted, onUnmounted} from 'vue'
import axios from 'axios'
import {useArtworkActions} from '@/stores/useArtworkActions'
import newChatModal from "@/Components/Messenger/NewChatModal.vue";

const artworkActions = useArtworkActions()

const emit = defineEmits(['close'])

const props = defineProps({
    artworkId: Number,
    chats: Array,
})

const filter = ref('')
const selected = ref([])

const filtered = computed(() =>
    props.chats.filter(c =>
        c.title.toLowerCase().includes(filter.value.toLowerCase())
    )
)

onMounted(() => {
    document.body.style.overflow = 'hidden'
})

onUnmounted(() => {
    document.body.style.overflow = ''
})

function close() {
    emit('close')
}

async function share() {
    for (let convId of selected.value) {
        await axios.post(
            `/messenger/conversations/${convId}/messages`,
            {artwork_id: props.artworkId}
        )
    }
    selected.value = []
    filter.value = ''
    artworkActions.notify('Сообщения отправлены')
    emit('close')
}
</script>

<template>
    <div
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
        @click.self="close"
    >
        <div class="bg-base-100 rounded-lg w-96 p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-semibold">Выберите чаты</h3>
                <button class="btn btn-sm btn-ghost" @click="close">✖</button>
            </div>

            <input
                v-model="filter"
                type="text"
                placeholder="Поиск…"
                class="input input-sm w-full mb-3"
            />

            <div class="max-h-64 overflow-auto space-y-2 mb-4">
                <label
                    v-for="c in filtered"
                    :key="c.id"
                    class="flex items-center gap-2 px-3 py-2 rounded-md cursor-pointer hover:bg-base-200 transition"
                >
                    <input
                        type="checkbox"
                        class="checkbox checkbox-sm"
                        v-model="selected"
                        :value="c.id"
                    />
                    <div class="flex items-center gap-2">
                        <img
                            :src="c.avatar_url || '/images/default-avatar.png'"
                            class="w-10 h-10 rounded-full object-cover"
                            alt="avatar"
                        />
                        <span>{{ c.title }}</span>
                    </div>
                </label>
            </div>

            <div class="flex justify-end gap-2">
                <div class="border-b border-base-300">
                    <button @click="newChatModal.open()"
                            class="btn btn-outline btn-sm w-full flex items-center justify-center gap-2">
                        + <span>Новый чат</span>
                    </button>
                </div>
                <button class="btn btn-sm btn-ghost" @click="close">
                    Отмена
                </button>
                <button
                    class="btn btn-sm btn-primary"
                    @click="share"
                    :disabled="!selected.length"
                >
                    Поделиться
                </button>
            </div>
            <NewChatModal ref="newChatModal"/>
        </div>
    </div>
</template>
