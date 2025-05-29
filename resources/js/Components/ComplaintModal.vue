<!-- resources/js/Components/Complaints/ComplaintModal.vue -->
<script setup>
import { ref, watch, nextTick } from 'vue'
import axios from 'axios'
import { useArtworkActions } from '@/stores/useArtworkActions'

const { requireAuth, notify, showAuthModal } = useArtworkActions()

const props = defineProps({
    show: Boolean,
    types: Array,
    targetType: String,   // 'artwork' | 'comment' | 'profile'
    targetId: [String, Number],
})
const emit = defineEmits(['close', 'submitted'])

const dialogRef = ref(null)
const form = ref({ type: '', details: '' })
const processing = ref(false)

watch(() => props.show, (v) => {
    if (v) {
        nextTick(() => dialogRef.value?.showModal())
    } else {
        dialogRef.value?.close()
    }
})

watch(() => props.show, (v) => {
    if (!v) form.value = { type: '', details: '' }
})

function close() {
    emit('close')
}

async function submit() {
    if (!requireAuth(() => submit())) return

    processing.value = true
    try {
        await axios.post(`/complaints/${props.targetType}/${props.targetId}`, form.value)
        notify('Ваша жалоба отправлена')
        emit('submitted')
    } catch (e) {
        console.error(e)
        if (e.response?.status === 401) {
            showAuthModal.value = true
        } else {
            notify('Ошибка отправки жалобы')
        }
    } finally {
        processing.value = false
        close()
    }
}
</script>

<template>
    <dialog ref="dialogRef" class="modal" @close="close">
        <div class="modal-box w-full max-w-md p-6">
            <h3 class="text-xl font-semibold mb-4">Пожаловаться</h3>

            <label class="block mb-3">
                <span class="block mb-1">Причина</span>
                <select v-model="form.type" class="select select-bordered w-full">
                    <option disabled value="">— выберите тип —</option>
                    <option v-for="t in types" :key="t.slug" :value="t.slug">
                        {{ t.name }}
                    </option>
                </select>
            </label>

            <label class="block mb-4">
                <span class="block mb-1">Описание (опционально)</span>
                <textarea
                    v-model="form.details"
                    class="textarea textarea-bordered w-full"
                    rows="3"
                    placeholder="Добавьте детали…"
                ></textarea>
            </label>

            <div class="flex justify-end space-x-2">
                <form method="dialog">
                    <button class="btn">Отмена</button>
                </form>
                <button
                    class="btn btn-warning"
                    :disabled="!form.type || processing"
                    @click="submit"
                >
                    {{ processing ? 'Отправка…' : 'Отправить' }}
                </button>
            </div>
        </div>

        <!-- фон, клик по которому закроет модалку -->
        <form method="dialog" class="modal-backdrop">
            <button></button>
        </form>
    </dialog>
</template>
