<template>
    <button
        @click="$emit('click')"
        :class="[
      active
        ? 'bg-primary text-primary-content'
        : 'bg-base-200 text-base-content hover:bg-base-300'
    ]"
        class="flex items-center rounded-full overflow-hidden font-medium whitespace-nowrap
           text-sm md:text-base
           max-w-[200px] md:max-w-[260px]
           h-10 md:h-12"
    >
        <!-- Thumb -->
        <div class="w-10 h-10 md:w-12 md:h-12 flex-shrink-0 overflow-hidden rounded-full ring ring-base-content/10">
            <img
                v-if="tag.thumb"
                :src="tag.thumb"
                class="w-full h-full object-cover"
                alt=""
            />
        </div>

        <!-- Label -->
        <span class="px-3 md:px-4 max-w-[120px] md:max-w-[160px] overflow-hidden whitespace-nowrap text-ellipsis">
      {{ tag.name }}
    </span>
    </button>
</template>

<script setup>
defineProps({
    tag: Object,
    active: Boolean,
})
</script>
