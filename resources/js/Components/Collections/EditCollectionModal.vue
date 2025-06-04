<template>
    <dialog ref="dialogRef" class="modal" @click.self="emitClose" @close="emitClose">
        <div class="modal-box bg-base-100 dark:bg-base-900 text-base-content dark:text-base-content">
            <h3 class="font-bold text-lg">Редактировать коллекцию</h3>
            <input
                v-model="local.name"
                type="text"
                placeholder="Название"
                class="w-full mt-2 px-2 py-1 rounded border border-base-300 dark:border-base-700
               bg-base-200 dark:bg-base-800 placeholder-base-content/50 focus:outline-none"
            />
            <label class="flex items-center space-x-2 mt-2">
                <input
                    type="checkbox"
                    v-model="local.is_private"
                    class="checkbox checkbox-primary"
                />
                <span>Приватная</span>
            </label>
            <div class="modal-action">
                <button class="btn" @click="emitClose">Отмена</button>
                <button
                    class="btn btn-primary"
                    @click="saveChanges"
                    :disabled="!local.name.trim()"
                >Сохранить</button>
            </div>
        </div>
    </dialog>
</template>

<script setup>
import { reactive, watch, ref, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
    initialCollection: { type: Object, required: true }
})
const emit = defineEmits(['close', 'saved'])

const local = reactive({
    name: props.initialCollection.name || '',
    is_private: !!props.initialCollection.is_private
})

watch(
    () => props.initialCollection,
    (val) => {
        local.name = val.name
        local.is_private = !!val.is_private
    }
)

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

function saveChanges() {
    emit('saved', { name: local.name.trim(), is_private: local.is_private })
    emitClose()
}
</script>
