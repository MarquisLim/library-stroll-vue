<template>
    <Swiper
        :modules="[Navigation, Mousewheel]"
        slides-per-view="auto"
        space-between="12"
        mousewheel
        class="py-1"
    >
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
