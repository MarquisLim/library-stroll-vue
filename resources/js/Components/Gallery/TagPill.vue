<template>
    <button
        @click="$emit('click')"
        :class="active
      ? 'bg-purple-600 text-white'
      : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
        class="flex items-center rounded-full overflow-hidden text-base font-medium max-w-[260px] h-12"
    >
        <!-- IMG -->
        <div class="w-12 h-12 flex-shrink-0 overflow-hidden rounded-full ring-1 ring-white/10">
            <img
                v-if="tag.thumb"
                :src="tag.thumb"
                class="w-full h-full object-cover"
            >
        </div>

        <!-- Text -->
        <span class="px-4 max-w-[160px] overflow-hidden whitespace-nowrap text-ellipsis block">
            {{ tag.name }}
        </span>
    </button>
</template>

<script setup>
defineProps({ tag: Object, active: Boolean })
</script>
