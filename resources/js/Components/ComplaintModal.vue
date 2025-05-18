<!-- resources/js/Components/Complaints/ComplaintModal.vue -->
<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
            @click.self="close"
        >
            <div class="bg-base-100 rounded-lg p-6 w-full max-w-md">
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
                    <button class="btn" @click="close">Отмена</button>
                    <button
                        class="btn btn-warning"
                        :disabled="!form.type || processing"
                        @click="submit"
                    >
                        {{ processing ? 'Отправка…' : 'Отправить' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import { useArtworkActions } from '@/stores/useArtworkActions'

const { requireAuth, notify, showAuthModal } = useArtworkActions()

const props = defineProps({
    show:       Boolean,
    types:      Array,
    targetType: String,   // 'artwork' | 'comment' | 'profile'
    targetId:   [String, Number],
})
const emit = defineEmits(['close','submitted'])

const form = ref({ type: '', details: '' })
const processing = ref(false)

// reset on close
watch(() => props.show, v => {
    if (!v) form.value = { type:'', details: '' }
})

function close() {
    emit('close')
}

async function submit() {
    // 1) require login
    if (!requireAuth(() => submit())) return

    processing.value = true
    try {
        console.log(props.targetType)
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
