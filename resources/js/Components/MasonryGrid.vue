<template>
    <div ref="scrollContainer" class="relative overflow-y-auto overflow-x-hidden p-4">
        <div ref="grid" class="masonry-grid w-full">
            <div class="grid-sizer"></div>
            <div v-for="item in items" :key="item.id" class="grid-item">
                <slot :item="item" />
            </div>
        </div>

        <div class="text-center py-4">
            <div v-if="loading">
                <div class="loader"></div>
                <div class="text-gray-500 mt-2">Загружаем ещё...</div>
            </div>
            <div v-else-if="noMoreItems" class="text-gray-400">
                🎉 Вы долистали до конца!
            </div>
        </div>

        <div ref="sentinel"></div>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import Masonry from 'masonry-layout'
import imagesLoaded from 'imagesloaded'
import axios from 'axios'

const props = defineProps({
    items: {
        type: Array,
        required: true
    },
    loadMoreUrl: {
        type: String,
        required: false
    }
})

const emit = defineEmits(['update:items'])

const grid = ref(null)
const scrollContainer = ref(null)
const sentinel = ref(null)

let msnry
let observer

const loading = ref(false)
const page = ref(0)
const noMoreItems = ref(false)

function sentinelVisible(){
    const rect = sentinel.value?.getBoundingClientRect()
    return rect && rect.top < window.innerHeight
}

function initMasonry() {
    msnry = new Masonry(grid.value, {
        itemSelector: '.grid-item',
        columnWidth: '.grid-sizer',
        percentPosition: true,
        gutter: 0
    })
    imagesLoaded(grid.value).on('progress', () => msnry.layout())
}

function reloadMasonry() {
    nextTick(() => {
        imagesLoaded(grid.value).on('progress', () => {
            msnry.reloadItems()
            msnry.layout()
        })
    })
}

function handleIntersect(entries) {
    if (entries[0].isIntersecting && !loading.value && props.loadMoreUrl && !noMoreItems.value) {
        loadMore()
    }
}

function loadMore() {
    loading.value = true
        const url = props.loadMoreUrl +
                     (props.loadMoreUrl.includes('?') ? '&' : '?') +
                     'page=' + (page.value + 1)
            axios.get(url)
                .then(res => {
            if (res.data.artworks && res.data.artworks.length > 0) {
                emit('update:items', [...props.items, ...res.data.artworks])
                page.value++
            } else {
                noMoreItems.value = true
            }
        }).catch(() => {
                +            /* если после добавления Sentinel всё ещё виден
+               и сервер вернул ≥ perPage (20) — грузим следующую */
                                nextTick(()=>{
                                      if(!noMoreItems.value
                                             && res.data.artworks.length === 20
                                             && sentinelVisible()){
                                            loadMore()
                                          }
                                    })
                        }).catch(() => {
        noMoreItems.value = true
    }).finally(() => {
        loading.value = false
    })
}

onMounted(() => {
    initMasonry()

    observer = new IntersectionObserver(handleIntersect, { threshold: 0.25 })
    if (sentinel.value) observer.observe(sentinel.value)
})

watch(() => [...props.items], reloadMasonry)

/* сбрасываем page / noMore, когда **URL** поменялся (новый набор фильтров) */
watch(() => props.loadMoreUrl, () => {
      page.value        = 0
      noMoreItems.value = false
    loading.value     = false
         msnry?.destroy()
         nextTick(loadMore)

})


onBeforeUnmount(() => {
    if (observer && sentinel.value) {
        observer.unobserve(sentinel.value)
    }
})
</script>

<style scoped>
.grid-sizer,
.grid-item {
    width: 16.6667%;
}

.grid-item img,
.grid-item video {
    will-change: opacity;
}

.grid-item {
    padding-left: 4px;
    padding-right: 4px;
    margin-bottom: 8px;
}

/* Responsive */
@media (max-width: 1024px) {
    .grid-sizer,
    .grid-item {
        width: 33.3333%;
    }
}

@media (max-width: 768px) {
    .grid-sizer,
    .grid-item {
        width: 50%;
    }
}

@media (max-width: 640px) {
    .grid-sizer,
    .grid-item {
        width: 100%;
    }
}

/* в <style scoped> MasonryGrid.vue */
.grid-item img,
.grid-item video {
    width: 100%;
    height: auto;
    aspect-ratio: 1 / 1;   /* квадрат-заглушка */
    opacity: 0;
    transition: opacity .3s;
}
.grid-item img.loaded,
.grid-item video.loaded { opacity: 1 }


/* Красивая крутилка */
.loader {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3490dc;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
