<!-- resources/js/Components/Studio/ConfirmDeleteModal.vue -->
<template>
    <div class="modal modal-open" @click.self="$emit('close')">
        <div class="modal-box max-w-md">
            <h2 class="text-lg font-semibold flex items-center gap-2 mb-2">
                <i class="mdi mdi-trash-can-outline"></i> Удалить работу?
            </h2>
            <p class="text-sm opacity-70 mb-4">
                Это действие нельзя отменить.
            </p>
            <div class="modal-action justify-end">
                <button class="btn">Отмена</button>
                <button class="btn btn-error" @click="$emit('confirm')">Удалить</button>
            </div>
        </div>
    </div>
</template>

<script setup>
// нет скрипта
</script>

<style scoped>
/* стили DaisyUI берутся из плагина, кастом не нужен */
</style>
