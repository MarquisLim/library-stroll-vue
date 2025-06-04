<template>
    <dialog ref="dialogRef" class="modal" @click.self="emitClose" @close="emitClose">
        <div class="modal-box bg-base-100 dark:bg-base-900 text-base-content dark:text-base-content">
            <h3 class="font-bold text-lg">Удалить коллекцию?</h3>
            <p class="my-2 text-base-content/60">
                Вы уверены, что хотите удалить коллекцию «{{ collection.name }}»?
            </p>
            <div class="modal-action">
                <button class="btn" @click="emitClose">Отмена</button>
                <button class="btn btn-error" @click="emitConfirmed">Удалить</button>
            </div>
        </div>
    </dialog>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({ collection: Object })
const emit = defineEmits(['close', 'confirmed'])

const dialogRef = ref(null)

onMounted(() => {
    dialogRef.value?.showModal()
})

onBeforeUnmount(() => {
    if (dialogRef.value?.open) {
        dialogRef.value.close()
    }
})

function emitClose() {
    emit('close')
}

function emitConfirmed() {
    emit('confirmed')
    emitClose()
}
</script>
