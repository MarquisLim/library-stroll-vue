<!-- ConfirmDeleteModal.vue -->
<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
    open: Boolean
})
const emit = defineEmits(['close', 'confirm'])
const dialogRef = ref(null)

watch(
    () => props.open,
    v => {
        if (v) {
            dialogRef.value.showModal()
        } else {
            dialogRef.value.close()
        }
    }
)
</script>

<template>
    <dialog
        ref="dialogRef"
        class="modal"
        @close="emit('close')"
        @click.self="emit('close')"
    >
        <div class="modal-box max-w-md">
            <h2 class="text-lg font-semibold flex items-center gap-2 mb-2">
                <i class="mdi mdi-trash-can-outline"></i>Удалить работу?
            </h2>
            <p class="text-sm opacity-70 mb-4">Это действие нельзя отменить.</p>
            <div class="modal-action justify-end">
                <button class="btn" @click="emit('close')">Отмена</button>
                <button class="btn btn-error" @click="emit('confirm')">Удалить</button>
            </div>
        </div>
    </dialog>
</template>
