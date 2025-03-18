<template>
    <div class="modal modal-open">
        <div class="modal-box bg-gray-800 text-white">
            <h3 class="font-bold text-lg">Удалить коллекцию?</h3>
            <p class="my-2 text-gray-300">Вы уверены, что хотите удалить коллекцию «{{ collection.name }}»?</p>

            <div class="modal-action">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <button class="btn btn-error" @click="$emit('confirmed')">Удалить</button>
            </div>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    collection: Object
})
const emit = defineEmits(['close','confirmed'])
</script>
