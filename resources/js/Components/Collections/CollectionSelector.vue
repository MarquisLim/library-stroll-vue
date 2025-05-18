<template>
    <!-- общий оверлей -->
    <div
        class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center md:block"
        @click.self="close"
    >
        <!-- Desktop pop-over -->
        <div
            v-if="!isMobile"
            ref="pop"
            class="absolute bg-base-100 dark:bg-base-900 text-base-content dark:text-base-content
             w-72 rounded-xl shadow-lg p-4"
            :style="popStyle"
        >
            <!-- header -->
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-semibold">Коллекции</h3>
                <button class="text-base-content/60 hover:text-base-content" @click="close">✕</button>
            </div>

            <!-- превью арта -->
            <div v-if="selectedArtThumb" class="flex items-center mb-3">
                <img :src="selectedArtThumb" class="w-10 h-10 rounded object-cover mr-2" />
                <span class="text-sm text-base-content/50 line-clamp-2">
          {{ selectedArtTitle }}
        </span>
            </div>

            <!-- поиск по коллекциям -->
            <input
                v-if="collections.length"
                v-model="filter"
                type="text"
                placeholder="Поиск…"
                class="w-full mb-3 px-3 py-1.5 rounded bg-base-200 dark:bg-base-800
               placeholder-base-content/50 focus:outline-none"
            />

            <!-- список -->
            <div
                v-if="collections.length"
                class="max-h-44 overflow-y-auto custom-scroll space-y-1 pr-1"
            >
                <label
                    v-for="col in filteredCollections"
                    :key="col.id"
                    class="flex items-center cursor-pointer
                 bg-base-200 dark:bg-base-800 hover:bg-base-300 dark:hover:bg-base-700
                 rounded px-2 py-1"
                >
                    <div class="w-9 h-9 mr-2 rounded bg-purple-700/40 flex-shrink-0 overflow-hidden">
                        <img
                            v-if="thumb(col)"
                            :src="thumb(col)"
                            class="w-full h-full object-cover"
                        />
                    </div>
                    <input
                        type="checkbox"
                        :value="col.id"
                        v-model="selectedCols"
                        class="checkbox checkbox-primary mr-2"
                    />
                    <span class="truncate flex-1">{{ col.name }}</span>
                </label>
            </div>

            <button
                v-if="collections.length"
                class="btn btn-primary w-full mt-3"
                @click="save"
            >Сохранить</button>
            <button
                class="btn w-full mt-2 bg-base-200 dark:bg-base-800 hover:bg-base-300 dark:hover:bg-base-700"
                @click="$emit('createCollection')"
            >Создать коллекцию</button>
        </div>

        <!-- Mobile bottom-sheet -->
        <div
            v-else
            class="fixed inset-x-0 bottom-0 bg-base-100 dark:bg-base-900 text-base-content dark:text-base-content
             rounded-t-2xl p-4 max-h-[80vh] shadow-2xl z-50"
        >
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-semibold">Коллекции</h3>
                <button class="text-base-content/60 hover:text-base-content" @click="close">✕</button>
            </div>

            <input
                v-if="collections.length"
                v-model="filter"
                type="text"
                placeholder="Поиск…"
                class="w-full mb-3 px-3 py-1.5 rounded bg-base-200 dark:bg-base-800
               placeholder-base-content/50 focus:outline-none"
            />

            <div
                v-if="collections.length"
                class="overflow-y-auto custom-scroll space-y-1 pr-1"
                style="max-height:50vh"
            >
                <label
                    v-for="col in filteredCollections"
                    :key="col.id"
                    class="flex items-center cursor-pointer
                 bg-base-200 dark:bg-base-800 hover:bg-base-300 dark:hover:bg-base-700
                 rounded px-2 py-1"
                >
                    <div class="w-9 h-9 mr-2 rounded bg-purple-700/40 flex-shrink-0 overflow-hidden">
                        <img
                            v-if="thumb(col)"
                            :src="thumb(col)"
                            class="w-full h-full object-cover"
                        />
                    </div>
                    <input
                        type="checkbox"
                        :value="col.id"
                        v-model="selectedCols"
                        class="checkbox checkbox-primary mr-2"
                    />
                    <span class="truncate flex-1">{{ col.name }}</span>
                </label>
            </div>

            <button
                v-if="collections.length"
                class="btn btn-primary w-full mt-3"
                @click="save"
            >Сохранить</button>
            <button
                class="btn w-full mt-2 bg-base-200 dark:bg-base-800 hover:bg-base-300 dark:hover:bg-base-700"
                @click="$emit('createCollection')"
            >Создать коллекцию</button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useArtworkActions } from '@/stores/useArtworkActions'

const props = defineProps({
    position:            Object,
    selectedCollections: Array
})
const emit = defineEmits(['close','selected','createCollection'])

const { collections, selectedArt } = storeToRefs(useArtworkActions())

const filter       = ref('')
const selectedCols = ref([...(props.selectedCollections||[])])

watch(() => props.selectedCollections,
    v => selectedCols.value = v? [...v] : [])
watch(collections, ()=> filter.value = '')

const filteredCollections = computed(() =>
    collections.value.filter(c =>
        c.name.toLowerCase().includes(filter.value.toLowerCase())
    )
)

const isMobile = window.matchMedia('(max-width: 639px)').matches

const selectedArtThumb = computed(() =>
    selectedArt.value?.thumb_url
)
const selectedArtTitle = computed(() =>
    selectedArt.value?.title || 'Избранный арт'
)

function save()  { emit('selected', selectedCols.value) }
function close() { emit('close') }

function thumb(col){
    if(col.thumb) return col.thumb
    if(col.artworks?.length) return col.artworks[0].thumb_url
    return null
}

/* позиционирование */
const pop   = ref(null)
const popStyle = computed(() => {
    if (isMobile || !props.position) return {};
    let { top, left } = props.position;
    const { innerWidth, innerHeight } = window;
    const w = 288;
    const h = pop.value?.offsetHeight || 320;

    const normalize = v => typeof v === 'number' ? `${v}px` : v;

    if (typeof top === 'number') {
        if (top + h > innerHeight) top = innerHeight - h - 16;
        if (top < 16) top = 16;
    }
    if (typeof left === 'number') {
        if (left + w > innerWidth) left = innerWidth - w - 16;
        if (left < 16) left = 16;
    }

    return {
        top: normalize(top),
        left: normalize(left),
    };
});

onMounted(() => {
    if(pop.value) pop.value.style.position = 'fixed'
})
</script>

<style scoped>
.custom-scroll::-webkit-scrollbar { width:6px }
.custom-scroll::-webkit-scrollbar-thumb { background:rgba(107,114,128,.7); border-radius:4px }
</style>
