<template>
    <button
        @click="$emit('click')"
        :class="[
      active
        ? 'bg-primary text-primary-content'
        : 'bg-base-200 text-base-content hover:bg-base-300'
    ]"
        class="flex items-center rounded-full overflow-hidden text-base font-medium max-w-[260px] h-12"
    >
        <!-- Thumb -->
        <div class="w-12 h-12 flex-shrink-0 overflow-hidden rounded-full ring ring-base-content/10">
            <img
                v-if="tag.thumb"
                :src="tag.thumb"
                class="w-full h-full object-cover"
                alt=""
            />
        </div>

        <!-- Label -->
        <span class="px-4 max-w-[160px] overflow-hidden whitespace-nowrap text-ellipsis block">
      {{ tag.name }}
    </span>
    </button>
</template>

<script setup>
defineProps({
    tag   : Object,
    active: Boolean
})
</script>
