<script setup>
import { ref, onMounted } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout        from '@/Layouts/AppLayout.vue'
import MasonryGrid      from '@/Components/MasonryGrid.vue'
import ArtworkCard      from '@/Components/Gallery/ArtworkCard.vue'
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import { useArtworkActions } from '@/stores/useArtworkActions'
import { Capacitor } from '@capacitor/core'

const { recentArtworks, collections } = usePage().props
const items = ref([])

const { setCollections } = useArtworkActions()
if (collections) setCollections(collections)

onMounted(() => {
    items.value = recentArtworks
})

const isInApp = ref(false)

onMounted(() => {
    if (Capacitor.getPlatform() !== 'web') {
        isInApp.value = true
    }
})
</script>

<template>
    <AppLayout>
        <Head title="Главная"/>

        <!-- Hero -->
        <section class="min-h-screen relative flex items-center justify-center bg-cover bg-center" style="background-image:url('/images/main/main_section.webp')">
            <div class="absolute inset-0 bg-black/70"></div>
            <div class="relative z-10 text-center px-4">
                <img src="/logo.png" class="mx-auto w-40 md:w-60 mb-6"/>
                <h1 class="text-5xl md:text-6xl font-extrabold text-white">
                    Открой <span class="text-primary">LibraryStroll</span>
                </h1>
                <p class="mt-4 text-lg md:text-xl text-white/80 max-w-2xl mx-auto">
                    Платформа для создания, коллекционирования и&nbsp;обсуждения цифрового искусства.
                </p>
            </div>
        </section>

        <!-- Recent artworks -->
        <section class="min-h-auto md:min-h-scree flex flex-col bg-base-200 py-16">
            <div class="flex-1 flex flex-col items-center px-4 w-full">
                <h2 class="text-4xl font-bold mb-8">Новые работы</h2>
                <div class="w-full">
                    <MasonryGrid :items="items">
                        <template #default="{ item }">
                            <ArtworkCard :art="item"/>
                        </template>
                    </MasonryGrid>
                </div>
                <Link href="/gallery" class="btn btn-primary mt-10">Перейти в галерею</Link>
            </div>
        </section>

        <!-- Create -->
        <section class="min-h-auto md:min-h-scree flex flex-col md:flex-row items-center bg-base-100 px-6 lg:px-16 py-16 gap-12">
            <div class="w-full md:w-1/2 space-y-6 text-center md:text-right">
                <h2 class="text-4xl font-bold">Создавай свои артворки</h2>
                <p class="text-lg text-base-content/70 md:text-right md:mx-0">
                    Загружай изображения и&nbsp;видео, добавляй описания и&nbsp;публикуй в&nbsp;пару кликов.
                </p>
            </div>
            <div class="w-full md:w-1/2 flex justify-center">
                <img src="/images/main/studio.jpg" class="w-3/4 rounded-lg shadow-lg object-cover" loading="lazy"/>
            </div>
        </section>

        <!-- Collections -->
        <section class="min-h-auto md:min-h-scree flex flex-col md:flex-row items-center bg-base-200 px-6 lg:px-16 py-16 gap-12">
            <div class="w-full md:w-1/2 flex justify-center">
                <img src="/images/main/collection.jpg" class="w-3/4 rounded-lg shadow-lg object-cover" loading="lazy" />
            </div>
            <div class="w-full md:w-1/2 space-y-6 text-center md:text-left">
                <h2 class="text-4xl font-bold">Организуй коллекции</h2>
                <p class="text-lg text-base-content/70 max-w-md mx-auto md:mx-0">
                    Создавай подборки любимых работ и&nbsp;делись ими.
                </p>
            </div>
        </section>

        <!-- Chat -->
        <section class="min-h-auto md:min-h-scree flex flex-col md:flex-row items-center bg-base-100 px-6 lg:px-16 py-16 gap-12">
            <div class="w-full md:w-1/2 space-y-6 text-center md:text-right order-2 md:order-1">
                <h2 class="text-4xl font-bold">Общайся в&nbsp;чате</h2>
                <p class="text-lg text-base-content/70 md:mx-0">
                    Обсуждай работы в&nbsp;реальном времени, оставляй отзывы.
                </p>
            </div>
            <div class="w-full md:w-1/2 flex justify-center order-1 md:order-2">
                <img src="/images/main/messenger.jpg" class="w-3/4 rounded-lg shadow-lg object-cover" loading="lazy" />
            </div>
        </section>

        <!-- App download -->
        <section
            v-if="!isInApp"
            class="min-h-auto md:min-h-screen relative flex items-center md:py-0 py-16 justify-center bg-cover bg-center"
            style="background-image:url('/images/main/app_section.jpg')"
        >
            <div class="absolute inset-0 bg-black/60"></div>
            <div class="relative z-10 flex flex-col items-center text-center px-4">
                <img
                    src="/images/main/app.png"
                    class="w-52 md:w-72 mb-10 object-contain"
                    loading="lazy"
                />
                <h2 class="text-4xl font-bold text-white mb-6">Скачать приложение</h2>
                <a
                    href="/app/library_stroll.apk"
                    download
                    class="btn btn-primary inline-flex items-center gap-2"
                >
                    <ArrowDownTrayIcon class="w-5 h-5" /> Скачать APK
                </a>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.min-h-screen{min-height:100vh}
</style>
