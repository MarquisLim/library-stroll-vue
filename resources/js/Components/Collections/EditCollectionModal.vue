<template>
    <dialog ref="dialogRef" class="modal workspace-dialog" @click.self="emitClose" @close="emitClose">
        <div class="modal-box bg-base-100 text-base-content">
            <h3 class="font-bold text-lg">Редактировать коллекцию</h3>
            <p class="ui-muted text-sm">Название и доступ к вашей подборке.</p>
            <label for="edit-collection-name" class="block text-sm font-semibold">Название</label>
            <input
                id="edit-collection-name"
                v-model="local.name"
                type="text"
                placeholder="Название"
                class="input mt-2 w-full"
            />
            <label class="studio-setting mt-5 cursor-pointer">
                <span>Приватная коллекция</span>
                <input
                    type="checkbox"
                    v-model="local.is_private"
                    class="toggle toggle-primary toggle-sm"
                />
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
