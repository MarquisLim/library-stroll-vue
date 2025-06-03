<template>
    <Swiper
        :modules="[Navigation, Mousewheel]"
        slides-per-view="auto"
        space-between="12"
        mousewheel
        class="py-1"
    >
        <SwiperSlide class="!w-auto">
            <button
                @click="$emit('update:modelValue', 'all')"
                :class="modelValue === 'all'
          ? 'bg-primary text-primary-content'
          : 'bg-gray-100 text-gray-700 hover:bg-gray-200 md:bg-base-200 md:text-base-content md:hover:bg-base-300'"
                class="flex items-center rounded-full overflow-hidden font-medium whitespace-nowrap
               text-sm md:text-base
               h-10 md:h-12
               px-3 md:px-5"
            >
                Все
            </button>
        </SwiperSlide>
        <SwiperSlide
            v-for="tag in tags"
            :key="tag.id"
            class="!w-auto"
        >
            <TagPill
                :tag="tag"
                :active="modelValue === tag.name"
                @click="select(tag.name)"
            />
        </SwiperSlide>
    </Swiper>
</template>

<script setup>
import { Swiper, SwiperSlide }   from 'swiper/vue'
import { Navigation, Mousewheel } from 'swiper/modules'
import 'swiper/css'
import 'swiper/css/navigation'

import TagPill from '@/Components/Gallery/TagPill.vue'

const props = defineProps({
    tags      : Array,
    modelValue: String
})
const emit = defineEmits(['update:modelValue'])
function select(name){
    emit('update:modelValue', name)
}
</script>
