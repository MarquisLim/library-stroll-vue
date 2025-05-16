<template>
    <div class="modal modal-open" @click.self="$emit('close')">
        <div class="modal-box max-w-md">
            <h2 class="text-lg font-semibold flex items-center gap-2 mb-2">
                <i class="mdi mdi-trash-can-outline"></i> Удалить пользователя?
            </h2>
            <p class="text-sm opacity-70 mb-4">
                Это действие нельзя отменить.
            </p>
            <div class="modal-action justify-end">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <!-- теперь эмитим confirmed -->
                <button class="btn btn-error" @click="$emit('confirmed')">Удалить</button>
            </div>
        </div>
    </div>
</template>

<script setup>
const emit = defineEmits(['close','confirmed'])
</script>
