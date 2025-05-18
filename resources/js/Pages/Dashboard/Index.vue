<script setup>
import { Head, Link } from '@inertiajs/vue3'
import ApexChart from 'vue3-apexcharts'
import StatTile       from '@/Components/Dashboard/StatTile.vue'
import ProfileCard    from '@/Components/Dashboard/ProfileCard.vue'
import ArtworkCard    from '@/Components/Gallery/ArtworkCard.vue'
import MasonryGrid    from '@/Components/MasonryGrid.vue'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { useArtworkActions } from '@/stores/useArtworkActions'
import { computed, onMounted } from 'vue'

defineOptions({ layout: DashboardLayout })

const props = defineProps({
    stats: Object,
    topArtwork: Object,
    likesChart: Object,
    recentWorks: { type:Array, default: ()=>[] },
    drafts:      { type:Array, default: ()=>[] },
    collections: { type:Array, default: ()=>[] },
})

const { setCollections } = useArtworkActions()
onMounted(()=> setCollections(props.collections))

const chartSeries = [
    { name: 'Отметки «Нравится»', data: Object.values(props.likesChart) }
]

const chartOptions = {
    chart: {
        type: 'area',
        height: 300,
        toolbar: { show: false },
        background: 'transparent',
        foreColor: 'var(--tw-prose-body)',
    },
    stroke: {
        curve: 'smooth',
        width: 3,
        colors: ['#3B82F6'],
    },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 0.4,
            opacityFrom: 0.5,
            opacityTo: 0.1,
            stops: [0, 90, 100],
            colorStops: [
                { offset: 0, color: '#3B82F6' },
                { offset: 100, color: '#3B82F6' },
            ],
        },
    },
    dataLabels: { enabled: false },
    grid: {
        borderColor: '#7480ff',
    },
    xaxis: {
        categories: Object.keys(props.likesChart),
        axisBorder:   { show: true, color: 'var(--tw-prose-body)' },
        axisTicks:    { show: true, color: 'var(--tw-prose-body)' },
        labels: {
            style: {
                colors: '#7480ff',
                fontSize: '12px',
            },
        },
    },
    yaxis: {
        axisBorder:   { show: true, color: 'var(--tw-prose-body)' },
        axisTicks:    { show: true, color: 'var(--tw-prose-body)' },
        labels: {
            style: {
                colors: '#7480ff',
                fontSize: '12px',
            },
        },
    },
    responsive: [
        {
            breakpoint: 768,
            options: {
                chart: { height: 200 },
                stroke: { width: 2 },
                legend: { show: false },
            },
        },
    ],
}


</script>

<template>
    <Head title="Dashboard" />

    <div class="flex bg-base-100 dark:bg-base-900 text-base-content min-h-screen">
        <main class="flex-1 p-4 md:p-6 space-y-12">
            <h1 class="text-3xl font-bold">Панель управления</h1>

            <!-- Статистика + популярная -->
            <section class="grid lg:grid-cols-3 gap-6 items-start">
                <div class="lg:col-span-2 grid grid-cols-2 sm:grid-cols-2 gap-4">
                    <StatTile label="Работы"      :value="stats.artworks"  icon="mdi mdi-brush" />
                    <StatTile label="Просмотры"   :value="stats.views"     icon="mdi mdi-eye" />
                    <StatTile label="Понравилось" :value="stats.likes"     icon="mdi mdi-heart" />
                    <StatTile label="Комментар."  :value="stats.comments"  icon="mdi mdi-comment" />
                </div>
                <div class="bg-base-200 dark:bg-base-800 rounded-xl shadow p-4 space-y-3 w-full">
                    <h2 class="text-lg font-semibold">Популярная работа</h2>
                    <template v-if="topArtwork">
                        <ArtworkCard :art="topArtwork" />
                    </template>
                    <p v-else class="text-center text-base-content/60 py-6">
                        Нет опубликованных работ.
                    </p>
                </div>
            </section>

            <!-- График -->
            <section class="bg-base-200 dark:bg-base-800 rounded-xl p-6 shadow">
                <h2 class="text-lg font-semibold mb-4">Динамика лайков (30 дней)</h2>
                <ApexChart
                    type="area"
                    :series="chartSeries"
                    :options="chartOptions"
                />
            </section>

            <!-- Последние работы -->
            <section v-if="recentWorks.length" class="space-y-4">
                <h2 class="text-lg font-semibold">Последние работы</h2>
                <MasonryGrid :items="recentWorks">
                    <template #default="{ item }">
                        <ArtworkCard :art="item" />
                    </template>
                </MasonryGrid>
            </section>

            <!-- Черновики -->
            <section v-if="drafts.length" class="space-y-4">
                <h2 class="text-lg font-semibold">Черновики</h2>
                <MasonryGrid :items="drafts">
                    <template #default="{ item }">
                        <Link
                            :href="route('studio.index')"
                            class="block group"
                        >
                            <img
                                :src="item.thumb_url"
                                class="w-full h-48 rounded-lg object-cover group-hover:opacity-80 transition"
                            />
                            <p class="mt-2 text-center text-sm text-base-content/60">
                                {{ item.title || 'Без названия' }}
                            </p>
                        </Link>
                    </template>
                </MasonryGrid>
            </section>
        </main>

        <aside class="hidden xl:block w-80 p-6">
            <ProfileCard />
        </aside>
    </div>
</template>
