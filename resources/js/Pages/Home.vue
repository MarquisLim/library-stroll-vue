<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import MasonryGrid from '@/Components/MasonryGrid.vue'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import { ArrowDownTrayIcon, ArrowUpRightIcon, ArrowRightIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { useArtworkActions } from '@/stores/useArtworkActions'
import { Capacitor } from '@capacitor/core'

const page = usePage()
const items = computed(() => page.props.recentArtworks || [])
const { setCollections } = useArtworkActions()
if (page.props.collections) setCollections(page.props.collections)
const isInApp = ref(false)
onMounted(() => { isInApp.value = Capacitor.getPlatform() !== 'web' })
</script>

<template>
    <AppLayout>
        <Head title="Искусство, которое близко" />
        <section class="ui-home-hero">
            <div class="max-w-xl">
                <p class="ui-eyebrow mb-6">Место для искусства и вдохновения</p>
                <h1 class="text-4xl leading-[1.08] sm:text-6xl xl:text-7xl">Находите то,<br>что <span class="text-primary">откликается.</span></h1>
                <p class="ui-muted mt-7 max-w-md text-base leading-relaxed sm:text-lg">Цифровое искусство, новые имена и ваши будущие любимые работы. Смотрите, сохраняйте и создавайте свою историю.</p>
                <div class="mt-9 flex flex-wrap gap-3">
                    <Link href="/gallery" class="btn btn-primary h-12 px-6">Открыть галерею <ArrowUpRightIcon class="h-4 w-4" /></Link>
                    <Link href="/studio" class="btn btn-ghost h-12 px-5">Я создаю искусство <ArrowRightIcon class="h-4 w-4" /></Link>
                </div>
                <div class="mt-10 flex items-center gap-3 border-t border-base-300 pt-5 text-sm ui-muted">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><PhotoIcon class="h-4 w-4" /></span>
                    Пространство для вашего взгляда на мир
                </div>
            </div>
            <div class="ui-hero-art aspect-[5/4]">
                <img src="/images/main/main_section.webp" alt="Два персонажа плывут по сияющей фиолетовой реке" class="absolute inset-0 h-full w-full object-cover" fetchpriority="high" />
                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent px-7 pb-7 pt-20 text-white">
                    <p class="text-xs font-semibold uppercase tracking-widest text-white/70">LibraryStroll · Digital art</p>
                    <p class="mt-2 text-xl font-semibold tracking-tight">У каждого мира есть свой автор.</p>
                </div>
            </div>
        </section>
        <section class="border-y border-base-300 bg-base-100 py-12 sm:py-16">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="mb-7 flex flex-wrap items-end justify-between gap-5">
                    <div><p class="ui-eyebrow">Свежий взгляд</p><h2 class="mt-3 text-3xl sm:text-4xl">Новое в галерее</h2></div>
                    <Link href="/gallery" class="btn btn-ghost text-primary">Все работы <ArrowUpRightIcon class="h-4 w-4" /></Link>
                </div>
                <MasonryGrid v-if="items.length" :items="items">
                    <template #default="{ item }"><ArtworkCard :art="item" /></template>
                </MasonryGrid>
                <div v-else class="rounded-2xl border border-dashed border-base-300 bg-base-200 px-6 py-14 text-center">
                    <PhotoIcon class="mx-auto mb-4 h-9 w-9 text-primary" />
                    <h3 class="text-xl">Здесь начинается новая история</h3>
                    <p class="ui-muted mx-auto mt-3 max-w-md">Опубликуйте первую работу — пусть её увидят и найдут свои люди.</p>
                    <Link href="/studio" class="btn btn-primary mt-6">Перейти в студию <ArrowRightIcon class="h-4 w-4" /></Link>
                </div>
            </div>
        </section>
        <section class="ui-feature">
            <img src="/images/main/studio.jpg" alt="Творческое пространство художника" class="ui-feature-image" loading="lazy" />
            <div class="max-w-lg">
                <p class="ui-eyebrow">Для тех, кто создаёт</p>
                <h2 class="mt-4 text-3xl sm:text-4xl">Ваше искусство.<br>Ваша собственная студия.</h2>
                <p class="ui-muted mt-5 text-base leading-relaxed">Загружайте иллюстрации и видео, рассказывайте о своём процессе и делитесь работами с теми, кто их оценит.</p>
                <Link href="/studio" class="btn btn-outline btn-primary mt-7">Открыть студию <ArrowUpRightIcon class="h-4 w-4" /></Link>
            </div>
        </section>
        <section class="border-y border-base-300 bg-base-100">
            <div class="ui-feature">
                <div class="max-w-lg">
                    <p class="ui-eyebrow">Больше, чем избранное</p>
                    <h2 class="mt-4 text-3xl sm:text-4xl">Соберите свою<br>коллекцию впечатлений.</h2>
                    <p class="ui-muted mt-5 text-base leading-relaxed">Объединяйте любимые работы в подборки: по настроению, цвету или просто потому, что они вам близки.</p>
                    <Link href="/gallery" class="btn btn-outline btn-primary mt-7">Найти вдохновение <ArrowUpRightIcon class="h-4 w-4" /></Link>
                </div>
                <img src="/images/main/collection.jpg" alt="Подборка произведений искусства" class="ui-feature-image" loading="lazy" />
            </div>
        </section>
        <section class="ui-feature">
            <img src="/images/main/messenger.jpg" alt="Общение и обмен идеями" class="ui-feature-image" loading="lazy" />
            <div class="max-w-lg">
                <p class="ui-eyebrow">Творчество объединяет</p>
                <h2 class="mt-4 text-3xl sm:text-4xl">За каждой работой —<br>человек и разговор.</h2>
                <p class="ui-muted mt-5 text-base leading-relaxed">Обсуждайте искусство, знакомьтесь с авторами и делитесь идеями в личных и групповых чатах.</p>
                <Link href="/messenger" class="btn btn-outline btn-primary mt-7">К общению <ArrowUpRightIcon class="h-4 w-4" /></Link>
            </div>
        </section>
        <section v-if="!isInApp" class="mx-5 mb-16 max-w-7xl rounded-3xl bg-primary/10 px-6 py-12 text-center sm:mx-8 sm:px-12 lg:mx-auto">
            <p class="ui-eyebrow">Вдохновение рядом</p>
            <h2 class="mt-4 text-3xl sm:text-4xl">Возьмите галерею с собой</h2>
            <p class="ui-muted mx-auto mt-4 max-w-lg">Любимые работы, ваши коллекции и общение — в приложении LibraryStroll для Android.</p>
            <a href="/app/library_stroll_v04.apk" download class="btn btn-primary mt-7 h-12 px-6"><ArrowDownTrayIcon class="h-5 w-5" /> Скачать для Android</a>
        </section>
    </AppLayout>
</template>