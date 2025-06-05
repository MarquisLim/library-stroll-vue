<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { _adapters } from 'chart.js'
import StatTile                  from '@/Components/Dashboard/StatTile.vue'
import ProfileCard               from '@/Components/Dashboard/ProfileCard.vue'
import ArtworkCard               from '@/Components/Gallery/ArtworkCard.vue'
import MasonryGrid               from '@/Components/MasonryGrid.vue'
import DashboardLayout           from '@/Layouts/DashboardLayout.vue'
import { useArtworkActions }     from '@/stores/useArtworkActions'
import Chart                     from 'chart.js/auto'
import 'chartjs-adapter-date-fns'

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

const likesSel    = ref(props.likesDays)
const commentsSel = ref(props.commentsDays)

const push = () =>
    router.get(
        route('dashboard'),
        { likes: likesSel.value, comments: commentsSel.value },
        { replace: true, preserveScroll: true }
    )
watch(likesSel,    push)
watch(commentsSel, push)

const parseDate = d => new Date(d)

const likesDataset = computed(() =>
    Object.entries(props.likesChart).map(([date, val]) => ({ x: date, y: val }))
)
const commentsDataset = computed(() =>
    Object.entries(props.commentsChart).map(([date, val]) => ({ x: date, y: val }))
)

const likesCanvas    = ref(null)
const commentsCanvas = ref(null)

let likesChartInstance = null
let commentsChartInstance = null

const buildConfig = (dataset, days, color) => {
    const unit = days === 365 ? 'month' : 'day'
    return {
        type: 'line',
        data: {
            datasets: [{
                label: '',
                data: dataset,
                fill: true,
                borderColor: color,
                backgroundColor: ctx => {
                    const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200)
                    g.addColorStop(0, color + '80')
                    g.addColorStop(1, color + '10')
                    return g
                },
                tension: 0.4,
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: color,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit,
                        tooltipFormat: days === 365 ? 'MM.yyyy' : 'dd.MM.yyyy',
                        displayFormats: {
                            day: 'dd.MM',
                            month: 'MM.yyyy'
                        }
                    },
                    ticks: {
                        color: '#7480ff',
                        font: { size: 11 }
                    },
                    grid: {
                        color: '#7480ff33',
                        borderDash: [3]
                    },
                    border: { color: 'var(--tw-prose-body)' }
                },
                y: {
                    ticks: {
                        color: '#7480ff',
                        font: { size: 11 }
                    },
                    grid: {
                        color: '#7480ff33',
                        borderDash: [3]
                    },
                    border: { color: 'var(--tw-prose-body)' }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title(context) {
                            const dt = context[0].parsed.x
                            const dateObj = new Date(dt)
                            if (days === 365) {
                                return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' }).format(dateObj)
                            }
                            return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(dateObj)
                        },
                        label(context) {
                            return context.parsed.y + ' '
                        }
                    }
                }
            }
        }
    }
}

const initLikesChart = () => {
    if (likesChartInstance) likesChartInstance.destroy()
    likesChartInstance = new Chart(likesCanvas.value, buildConfig(
        likesDataset.value,
        likesSel.value,
        '#3B82F6'
    ))
}

const initCommentsChart = () => {
    if (commentsChartInstance) commentsChartInstance.destroy()
    commentsChartInstance = new Chart(commentsCanvas.value, buildConfig(
        commentsDataset.value,
        commentsSel.value,
        '#F97316'
    ))
}

onMounted(async () => {
    await nextTick()
    initLikesChart()
    initCommentsChart()
})

watch([likesDataset, likesSel], () => {
    if (likesChartInstance) {
        likesChartInstance.data.datasets[0].data = likesDataset.value
        likesChartInstance.options.scales.x.time.unit = likesSel.value === 365 ? 'month' : 'day'
        likesChartInstance.options.scales.x.time.tooltipFormat = likesSel.value === 365 ? 'd MMM yyyy' : 'dd.MM.yyyy'
        likesChartInstance.update()
    }
})

watch([commentsDataset, commentsSel], () => {
    if (commentsChartInstance) {
        commentsChartInstance.data.datasets[0].data = commentsDataset.value
        commentsChartInstance.options.scales.x.time.unit = commentsSel.value === 365 ? 'month' : 'day'
        commentsChartInstance.options.scales.x.time.tooltipFormat = commentsSel.value === 365 ? 'd MMM yyyy' : 'dd.MM.yyyy'
        commentsChartInstance.update()
    }
})

useArtworkActions().setCollections(props.collections)
</script>

<template>
    <Head title="Dashboard"/>

    <div class="flex bg-base-100 dark:bg-base-900 min-h-screen">
        <main class="flex-1 p-4 md:p-6 space-y-12">
            <h1 class="text-3xl font-bold">Панель управления</h1>

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
                        <select v-model.number="likesSel" class="select ms-4">
                            <option :value="7">7 дн.</option>
                            <option :value="30">30 дн.</option>
                            <option :value="365">Год</option>
                        </select>
                    </div>
                    <div class="relative h-60">
                        <canvas ref="likesCanvas"></canvas>
                    </div>
                </div>

                <div class="bg-base-200 dark:bg-base-800 rounded-xl p-6 shadow space-y-4">
                    <div class="flex justify-between items-center">
                        <h2 class="font-semibold">Комментарии</h2>
                        <select v-model.number="commentsSel" class="select ms-4">
                            <option :value="7">7 дн.</option>
                            <option :value="30">30 дн.</option>
                            <option :value="365">Год</option>
                        </select>
                    </div>
                    <div class="relative h-60">
                        <canvas ref="commentsCanvas"></canvas>
                    </div>
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
