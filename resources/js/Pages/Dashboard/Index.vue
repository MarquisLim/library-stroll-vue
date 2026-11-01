<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import WorkspaceHeader from '@/Components/Workspace/WorkspaceHeader.vue'
import WorkspaceEmptyState from '@/Components/Workspace/WorkspaceEmptyState.vue'
import { useTheme } from '@/composables/useTheme'
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

const { isDark } = useTheme()
function chartColors() {
    const style = getComputedStyle(document.documentElement)
    const hex = name => '#' + style.getPropertyValue(name).trim().split(/\s+/).map(value => Number(value).toString(16).padStart(2, '0')).join('')
    return { primary: hex('--rgb-primary'), secondary: hex('--rgb-secondary'), muted: style.getPropertyValue('--ui-muted').trim(), grid: hex('--rgb-base-content') + '12' }
}
watch(isDark, async () => { await nextTick(); initLikesChart(); initCommentsChart() })
onBeforeUnmount(() => { likesChartInstance?.destroy(); commentsChartInstance?.destroy() })

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
                    g.addColorStop(0, color + '28')
                    g.addColorStop(1, color + '02')
                    return g
                },
                tension: 0.4,
                borderWidth: 2,
                pointRadius: 2,
                pointHoverRadius: 5,
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
                        color: chartColors().muted,
                        font: { size: 11, family: 'Manrope Variable' }
                    },
                    grid: {
                        color: chartColors().grid,
                        borderDash: [3]
                    },
                    border: { display: false }
                },
                y: {
                    ticks: {
                        color: chartColors().muted,
                        font: { size: 11, family: 'Manrope Variable' }
                    },
                    grid: {
                        color: chartColors().grid,
                        borderDash: [3]
                    },
                    border: { display: false }
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
    if (!likesCanvas.value) return
    if (likesChartInstance) likesChartInstance.destroy()
    likesChartInstance = new Chart(likesCanvas.value, buildConfig(
        likesDataset.value,
        likesSel.value,
        chartColors().primary
    ))
}

const initCommentsChart = () => {
    if (!commentsCanvas.value) return
    if (commentsChartInstance) commentsChartInstance.destroy()
    commentsChartInstance = new Chart(commentsCanvas.value, buildConfig(
        commentsDataset.value,
        commentsSel.value,
        chartColors().secondary
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
    <Head title="Обзор кабинета"/>

    <div class="workspace-page">
        <section class="space-y-7">
            <WorkspaceHeader eyebrow="Ваше пространство" title="Обзор" description="Творчество в цифрах. Следите за откликом и планируйте следующую работу."><template #actions><Link :href="route('studio.index')" class="btn btn-primary"><i class="mdi mdi-plus" /> Новая работа</Link></template></WorkspaceHeader>

            <section class="grid gap-5 xl:grid-cols-3">
                <div class="grid grid-cols-2 gap-4 xl:col-span-2">
                    <StatTile label="Работы"      :value="stats.artworks" icon="mdi mdi-brush"/>
                    <StatTile label="Просмотры"   :value="stats.views"    icon="mdi mdi-eye"  />
                    <StatTile label="Понравилось" :value="stats.likes"    icon="mdi mdi-heart"/>
                    <StatTile label="Комментарии"  :value="stats.comments" icon="mdi mdi-comment"/>
                </div>

                <div class="ui-panel p-5 space-y-4">
                    <h2 class="font-semibold">Популярная работа</h2>
                    <div v-if="topArtwork" class="max-w-sm"><ArtworkCard :art="topArtwork"/></div>
                    <p v-else class="py-6 text-center text-base-content/60">Нет опубликованных работ.</p>
                </div>
            </section>

            <section class="grid xl:grid-cols-2 gap-5">
                <div class="ui-panel p-5 sm:p-6 space-y-4">
                    <div class="flex justify-between items-center">
                        <h2 class="font-semibold">Лайки</h2>
                        <select v-model.number="likesSel" class="select select-sm ms-3">
                            <option :value="7">7 дн.</option>
                            <option :value="30">30 дн.</option>
                            <option :value="365">Год</option>
                        </select>
                    </div>
                    <div class="relative h-60">
                        <canvas ref="likesCanvas"></canvas>
                    </div>
                </div>

                <div class="ui-panel p-5 sm:p-6 space-y-4">
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

            <section class="space-y-4">
                <h2 class="font-semibold">Последние работы</h2>
                <WorkspaceEmptyState v-if="!recentWorks.length" title="У каждой истории есть начало" description="Опубликуйте работу — здесь появятся ваши последние публикации." /><MasonryGrid v-else :items="recentWorks">
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
        </section>

        <aside class="max-w-sm">
            <ProfileCard/>
        </aside>
    </div>
</template>
