<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, computed, watch }   from 'vue'
import ApexChart                 from 'vue3-apexcharts'
import StatTile                  from '@/Components/Dashboard/StatTile.vue'
import ProfileCard               from '@/Components/Dashboard/ProfileCard.vue'
import ArtworkCard               from '@/Components/Gallery/ArtworkCard.vue'
import MasonryGrid               from '@/Components/MasonryGrid.vue'
import DashboardLayout           from '@/Layouts/DashboardLayout.vue'
import { useArtworkActions }     from '@/stores/useArtworkActions'

defineOptions({ layout: DashboardLayout })

const props = defineProps({
    stats:          Object,
    topArtwork:     Object,
    likesChart:     Object,
    commentsChart:  Object,
    likesDays:      Number,
    commentsDays:   Number,
    recentWorks:    Array,
    drafts:         Array,
    collections:    Array
})

/* выбранные диапазоны */
const likesSel    = ref(props.likesDays)
const commentsSel = ref(props.commentsDays)

/* навигация c сохранением селекторов */
const push = () =>
    router.get(
        route('dashboard'),
        { likes: likesSel.value, comments: commentsSel.value },
        { replace: true, preserveScroll: true }
    )
watch(likesSel,    push)
watch(commentsSel, push)

/* конвертер для подписи оси X */
const monthFmt = new Intl.DateTimeFormat('ru-RU', { month:'short' })
const day2     = v => String(v).padStart(2, '0')

const label = (days, dateObj, idx) => {
    if (days === 365)
        return dateObj.getDate() === 1 ? monthFmt.format(dateObj) : ''
    if (days === 30)
        return idx % 2 ? '' : day2(dateObj.getDate())
    return day2(dateObj.getDate())
}

/* серии данных */
const likesSeries    = computed(() => [{ name:'Лайки',       data:Object.values(props.likesChart)    }])
const commentsSeries = computed(() => [{ name:'Комментарии', data:Object.values(props.commentsChart) }])
const fullCats = Object.keys

/* билд опций графика */
const buildOpts = (cats, color) => ({
    chart : { type:'area', height:240, toolbar:{show:false}, background:'transparent',
        foreColor:'var(--tw-prose-body)'},
    stroke: { curve:'smooth', width:3, colors:[color] },
    fill  : { type:'gradient',
        gradient:{ shadeIntensity:.4, opacityFrom:.45, opacityTo:.05,
            stops:[0,90,100],
            colorStops:[{offset:0,color},{offset:100,color}] } },
    dataLabels:{ enabled:false },
    grid : { borderColor:'#7480ff', strokeDashArray:3 },

    /* --- главное изменение здесь --- */
    xaxis:{
        categories: cats,
        labels:{ formatter: val => val,   /* <— выводим как есть */
            style:{ colors:'#7480ff', fontSize:'11px' } },
        tooltip:{ enabled:true },
        axisBorder:{ show:true, color:'var(--tw-prose-body)' },
        axisTicks :{ show:true, color:'var(--tw-prose-body)' }
    },
    yaxis:{ labels:{ style:{ colors:'#7480ff', fontSize:'11px' } },
        axisBorder:{ show:true, color:'var(--tw-prose-body)' },
        axisTicks :{ show:true, color:'var(--tw-prose-body)' } },
    responsive:[{ breakpoint:768, options:{ chart:{ height:180 }, stroke:{ width:2 } } }]
})

const likeOpts    = computed(() =>
    buildOpts(fullCats(props.likesChart),    '#3B82F6')
)
const commentOpts = computed(() =>
    buildOpts(fullCats(props.commentsChart), '#F97316')
)

useArtworkActions().setCollections(props.collections)
</script>

<template>
    <Head title="Dashboard"/>

    <div class="flex bg-base-100 dark:bg-base-900 min-h-screen">
        <main class="flex-1 p-4 md:p-6 space-y-12">
            <h1 class="text-3xl font-bold">Панель&nbsp;управления</h1>

            <section class="grid lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 grid grid-cols-2 gap-4">
                    <StatTile label="Работы"      :value="stats.artworks" icon="mdi mdi-brush"/>
                    <StatTile label="Просмотры"   :value="stats.views"    icon="mdi mdi-eye"  />
                    <StatTile label="Понравилось" :value="stats.likes"    icon="mdi mdi-heart"/>
                    <StatTile label="Комментар."  :value="stats.comments" icon="mdi mdi-comment"/>
                </div>

                <div class="bg-base-200 dark:bg-base-800 rounded-xl shadow p-4 space-y-3">
                    <h2 class="font-semibold">Популярная работа</h2>
                    <ArtworkCard v-if="topArtwork" :art="topArtwork"/>
                    <p v-else class="py-6 text-center text-base-content/60">Нет опубликованных работ.</p>
                </div>
            </section>

            <section class="grid md:grid-cols-2 gap-6">
                <div class="bg-base-200 dark:bg-base-800 rounded-xl p-6 shadow space-y-4">
                    <div class="flex justify-between items-center">
                        <h2 class="font-semibold">Лайки</h2>
                        <select v-model.number="likesSel" class="select">
                            <option :value="7">7&nbsp;дн.</option>
                            <option :value="30">30&nbsp;дн.</option>
                            <option :value="365">Год</option>
                        </select>
                    </div>
                    <ApexChart type="area" :series="likesSeries" :options="likeOpts"/>
                </div>

                <div class="bg-base-200 dark:bg-base-800 rounded-xl p-6 shadow space-y-4">
                    <div class="flex justify-between items-center">
                        <h2 class="font-semibold">Комментарии</h2>
                        <select v-model.number="commentsSel" class="select">
                            <option :value="7">7&nbsp;дн.</option>
                            <option :value="30">30&nbsp;дн.</option>
                            <option :value="365">Год</option>
                        </select>
                    </div>
                    <ApexChart type="area" :series="commentsSeries" :options="commentOpts"/>
                </div>
            </section>

            <section v-if="recentWorks.length" class="space-y-4">
                <h2 class="font-semibold">Последние работы</h2>
                <MasonryGrid :items="recentWorks">
                    <template #default="{ item }"><ArtworkCard :art="item"/></template>
                </MasonryGrid>
            </section>

            <section v-if="drafts.length" class="space-y-4">
                <h2 class="font-semibold">Черновики</h2>
                <MasonryGrid :items="drafts">
                    <template #default="{ item }">
                        <Link :href="route('studio.index')" class="block group">
                            <img :src="item.thumb_url" class="w-full h-48 rounded-lg object-cover group-hover:opacity-80 transition"/>
                            <p class="mt-2 text-center text-sm text-base-content/60">{{ item.title || 'Без названия' }}</p>
                        </Link>
                    </template>
                </MasonryGrid>
            </section>
        </main>

        <aside class="hidden xl:block w-80 p-6">
            <ProfileCard/>
        </aside>
    </div>
</template>
