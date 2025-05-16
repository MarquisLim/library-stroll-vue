<template>
    <div class="relative p-4">
        <!-- GRID ------------------------------------------------------------>
        <div ref="grid" class="masonry-grid">
            <div class="grid-sizer"></div>

            <div v-for="it in items" :key="it.id" class="grid-item">
                <slot :item="it"/>
            </div>
        </div>

        <!-- FOOTER ---------------------------------------------------------->
        <div class="text-center py-4">
            <div v-if="loading">
                <div class="loader"></div>
                <p class="text-gray-500 mt-2">Загружаем…</p>
            </div>
            <p v-else-if="noMoreItems" class="text-gray-400">🎉 Вы долистали до конца!</p>
        </div>

        <div ref="sentinel"></div>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import Masonry      from 'masonry-layout'
import imagesLoaded from 'imagesloaded'
import axios        from 'axios'

/* ───── props / emits ───── */
const props = defineProps({
    items       : { type:Array,  required:true },
    loadMoreUrl : { type:String, default:'' },
    startPage   : { type:Number, default:0  },
    perPage     : { type:Number, default:20 }
})
const emit = defineEmits(['update:items'])

/* ───── refs / state ───── */
const grid     = ref(null)
const sentinel = ref(null)

let msnry, observer
const page        = ref(props.startPage) // 0 -> первая порция уже на странице
const loading     = ref(false)
const noMoreItems = ref(false)

/* ───── helpers ───── */
const debounce = (fn,ms=100)=>{ let h; return (...a)=>{ clearTimeout(h); h=setTimeout(()=>fn(...a),ms) } }

function mountMasonry(){
    msnry?.destroy()
    msnry = new Masonry(grid.value,{
        itemSelector   : '.grid-item',
        columnWidth    : '.grid-sizer',
        percentPosition: true,
        gutter         : 0              // <-- важное изменение
    })
    imagesLoaded(grid.value)
        .on('progress',()=>msnry.layout())
        .on('done'    ,()=>msnry.layout())
}
const onResize = debounce(()=>msnry?.layout(),100)

/* ───── загрузка следующей страницы ───── */
async function loadMore(){
    if(loading.value || noMoreItems.value || !props.loadMoreUrl) return
    loading.value = true
    const sep = props.loadMoreUrl.includes('?') ? '&' : '?'
    const url = `${props.loadMoreUrl}${sep}page=${page.value+1}`

    try{
        const {data} = await axios.get(url)
        const more   = data.artworks ?? []
        if(more.length){
            emit('update:items',[...props.items,...more])
            page.value++
        }else noMoreItems.value = true
    }catch{ noMoreItems.value = true }
    finally{
        loading.value = false
        nextTick(()=>{ msnry.layout(); autoLoadIfVisible() })
    }
}

/* если sentry уже на экране — грузим ещё */
function autoLoadIfVisible(){
    const rect = sentinel.value?.getBoundingClientRect()
    if(!noMoreItems.value && rect && rect.top < innerHeight) loadMore()
}
function onIntersect([e]){ if(e.isIntersecting) loadMore() }

/* ───── life-cycle ───── */
onMounted(()=>{
    mountMasonry()
    window.addEventListener('resize',onResize)

    observer = new IntersectionObserver(onIntersect,{threshold:.25})
    observer.observe(sentinel.value)
})
onBeforeUnmount(()=>{
    window.removeEventListener('resize',onResize)
    observer?.disconnect()
    msnry?.destroy()
})

/* ───── watch ───── */
watch( () => props.items.length, (n,o)=>{
    if(n>o) nextTick(()=>{ msnry.reloadItems(); msnry.layout() })
})
watch( () => props.startPage, val=>{
    page.value = val
    noMoreItems.value = false
    nextTick(mountMasonry)
})
</script>

<style scoped>
/* кол-во колонок: 6 / 3 / 2 / 1 */
.grid-sizer,
.grid-item{ box-sizing:border-box; width:calc(100%/6) }
@media (max-width:1024px){ .grid-sizer,.grid-item{ width:calc(100%/3) } }
@media (max-width:768px) { .grid-sizer,.grid-item{ width:50%           } }
@media (max-width:640px) { .grid-sizer,.grid-item{ width:100%          } }

/* внутренние отступы — они НЕ влияют на расчёт ширины */
.grid-item{ padding:0 4px 8px }

/* плавное появление медиа */
.grid-item img,
.grid-item video{
    width:100%; height:auto; aspect-ratio:1/1;
    opacity:0; transition:opacity .3s
}
.grid-item img.loaded,
.grid-item video.loaded{ opacity:1 }

/* крутилка */
.loader{
    border:4px solid var(--tw-prose-body);
    border-top:4px solid var(--tw-prose-pre-bg);
    border-radius:50%;
    width:36px; height:36px;
    animation:spin 1s linear infinite;
    margin:0 auto;
}
@keyframes spin{ to{transform:rotate(360deg)} }
</style>
