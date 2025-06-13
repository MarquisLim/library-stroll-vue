<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import axios from 'axios'
import { useArtworkActions } from '@/stores/useArtworkActions'
import NewChatModal from '@/Components/Messenger/NewChatModal.vue'

const emit = defineEmits(['close'])
const props = defineProps({
    artworkId: Number,
    chats:     Array,
})

const artworkActions = useArtworkActions()
const dialogRef      = ref(null)
const newChatModal   = ref(null)
const filter         = ref('')
const selected       = ref([])

const filtered = computed(() =>
    props.chats.filter(c =>
        c.title.toLowerCase().includes(filter.value.toLowerCase())
    )
)

onMounted(() => {
    nextTick(() => dialogRef.value.showModal())
})

function openNewChat() {
    newChatModal.value.open()
}

async function share() {
    for (const convId of selected.value) {
        await axios.post(
            `/messenger/conversations/${convId}/messages`,
            { artwork_id: props.artworkId }
        )
    }
    artworkActions.notify('Сообщения отправлены')
    dialogRef.value.close()
}
</script>

<template>
    <dialog
        ref="dialogRef"
        class="modal"
        @close="emit('close')"
    >
        <div class="modal-box w-96 p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-semibold">Выберите чаты</h3>
                <button
                    class="btn btn-sm btn-ghost"
                    @click="dialogRef.close()"
                >✖</button>
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
                    <img
                        :src="c.avatar_url || '/images/default-avatar.png'"
                        class="w-10 h-10 rounded-full object-cover"
                        alt="avatar"
                    />
                    <span>{{ c.title }}</span>
                </label>
            </div>

            <div class="flex justify-end gap-2">
                <button
                    @click="openNewChat"
                    class="btn btn-outline btn-sm flex items-center gap-2"
                >
                    + Новый чат
                </button>

                <form method="dialog">
                    <button class="btn btn-sm btn-ghost">Отмена</button>
                </form>

                <button
                    class="btn btn-sm btn-primary"
                    @click.prevent="share"
                    :disabled="!selected.length"
                >
                    Поделиться
                </button>
            </div>
        </div>

        <form method="dialog" class="modal-backdrop">
            <button></button>
        </form>

        <NewChatModal ref="newChatModal" />
    </dialog>
</template>
