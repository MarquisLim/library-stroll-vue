<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link } from '@inertiajs/vue3'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline'

gsap.registerPlugin(ScrollTrigger)

const container = ref(null)

async function initAnimations() {
    await nextTick()
    const scroller = container.value

    // Hero
    gsap.from('.hero-content', {
        opacity: 0,
        y: 50,
        duration: 1,
        ease: 'power3.out'
    })

    const animations = [
        { sel: '.gallery-item',        trg: '.section-gallery',    from: { scale: 0.8 }, opts: { stagger: 0.2 } },
        { sel: '.gallery-left',        trg: '.section-gallery',    from: { x: -100 } },
        { sel: '.create-image',        trg: '.section-create',     from: { x: -100 } },
        { sel: '.create-text',         trg: '.section-create',     from: { y: 50 } },
        { sel: '.collections-text',    trg: '.section-collections', from: { x: 100 } },
        { sel: '.collections-image',   trg: '.section-collections', from: { scale: 0.8 } },
        { sel: '.chat-image',          trg: '.section-chat',        from: { x: 100 } },
        { sel: '.chat-text',           trg: '.section-chat',        from: { y: 50 } },
        { sel: '.mobile-btn',          trg: '.section-mobile',      from: { scale: 0.8 } },
    ]

    animations.forEach(({ sel, trg, from, opts = {} }) => {
        gsap.from(sel, {
            opacity: 0,
            duration: 1,
            ease: 'power3.out',
            scrollTrigger: {
                scroller,
                trigger: trg,
                start: 'top bottom'
            },
            ...from,
            ...opts
        })
    })
}

onMounted(() => {
    initAnimations()
    document.documentElement.style.overflow = 'hidden'
})
onUnmounted(() => {
    document.documentElement.style.overflow = ''
})
</script>

<template>
    <AppLayout>
        <Head title="LibraryStroll" />

        <!-- основной контейнер -->
        <div
            ref="container"
            class="h-[calc(100vh-4rem)] overflow-y-auto scroll-smooth snap-y snap-mandatory"
        >
            <!-- Hero -->
            <section
                class="section-hero snap-start h-full flex items-center justify-center relative bg-cover bg-center"
                style="background-image:url('/images/main/main_section.webp')"
            >
                <div class="absolute inset-0 bg-black/60"></div>
                <div class="hero-content relative z-10 flex flex-col items-center justify-center h-full text-center px-4">
                    <img src="/logo.png" alt="LibraryStroll" class="w-40 md:w-60 mb-6" />
                    <h1 class="text-5xl md:text-6xl font-extrabold text-white leading-tight">
                        Открой <span class="text-primary">LibraryStroll</span>
                    </h1>
                    <p class="mt-4 text-lg md:text-xl text-white/80 max-w-2xl">
                        Платформа для создания, коллекционирования и обсуждения цифрового искусства.
                    </p>
                </div>
            </section>

            <!-- Gallery -->
            <section
                class="section-gallery snap-start h-full flex flex-col md:flex-row items-center px-8 bg-base-200"
            >
                <div class="gallery-left md:w-1/2 space-y-4">
                    <h2 class="text-4xl font-bold">Исследуй галерею</h2>
                    <p class="text-lg text-base-content/70 max-w-md">
                        Погрузись в работы талантливых авторов со всего мира.
                    </p>
                    <Link href="/gallery" class="btn btn-primary inline-flex items-center gap-2">
                        Галерея
                    </Link>
                </div>
                <div class="gallery-right md:w-1/2 grid grid-cols-3 grid-rows-2 gap-4 mt-8 md:mt-0">
                    <img src="/images/gallery/1.jpg" alt="" class="gallery-item row-span-2 col-span-2 object-cover w-full h-full rounded-lg"/>
                    <img src="/images/gallery/2.jpg" alt="" class="gallery-item object-cover w-full h-full rounded-lg"/>
                    <img src="/images/gallery/3.jpg" alt="" class="gallery-item object-cover w-full h-full rounded-lg"/>
                    <img src="/images/gallery/4.jpg" alt="" class="gallery-item object-cover w-full h-full rounded-lg"/>
                </div>
            </section>

            <!-- Create -->
            <section
                class="section-create snap-start h-full flex flex-col md:flex-row items-center px-8 bg-base-100"
            >
                <div class="create-image md:w-1/2 flex justify-center">
                    <img
                        src="/images/main/photo_2025-04-03_15-04-13.jpg"
                        alt="Create Art"
                        class="w-full max-w-md rounded-lg shadow-lg"
                    />
                </div>
                <div class="create-text md:w-1/2 mt-8 md:mt-0 text-right space-y-4">
                    <h2 class="text-4xl font-bold">Создавай свои артворки</h2>
                    <p class="text-lg text-base-content/70 max-w-md mx-auto md:mx-0 text-right">
                        Загружай изображения и видео, добавляй описания и публикуй в пару кликов.
                    </p>
                </div>
            </section>

            <!-- Collections -->
            <section
                class="section-collections snap-start h-full flex flex-col md:flex-row items-center px-8 bg-base-200"
            >
                <div class="collections-text md:w-1/2 space-y-4">
                    <h2 class="text-4xl font-bold">Организуй коллекции</h2>
                    <p class="text-lg text-base-content/70 max-w-md">
                        Создавай подборки любимых работ и делись ими.
                    </p>
                </div>
                <div class="collections-image md:w-1/2 flex justify-center mt-6 md:mt-0">
                    <img
                        src="/images/main/photo_2025-04-03_15-04-13.jpg"
                        alt="Collections"
                        class="w-full max-w-md rounded-lg shadow-lg"
                    />
                </div>
            </section>

            <!-- Chat -->
            <section
                class="section-chat snap-start h-full flex flex-col md:flex-row items-center px-8 bg-base-100"
            >
                <div class="chat-image md:w-1/2 flex justify-center">
                    <img
                        src="/images/main/photo_2025-04-03_15-04-13.jpg"
                        alt="Chat"
                        class="w-full max-w-md rounded-lg shadow-lg"
                    />
                </div>
                <div class="chat-text md:w-1/2 mt-8 md:mt-0 space-y-4 text-right">
                    <h2 class="text-4xl font-bold">Общайся в чате</h2>
                    <p class="text-lg text-base-content/70 max-w-md mx-auto md:mx-0">
                        Обсуждай работы в реальном времени, оставляй отзывы.
                    </p>
                </div>
            </section>

            <!-- Mobile App -->
            <section
                class="section-mobile snap-start h-full relative bg-cover bg-center"
                style="background-image:url('/images/main/app_section.jpg')"
            >
                <div class="absolute inset-0 bg-black/50"></div>
                <div class="mobile-text relative z-10 flex flex-col items-center justify-center h-full text-center px-4 space-y-6">
                    <h2 class="text-4xl font-bold text-white">Скачать приложение</h2>
                    <button class="mobile-btn btn btn-primary inline-flex items-center gap-2">
                        <ArrowDownTrayIcon class="w-5 h-5" /> Скачать
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
/* scroll-snap */
.scroll-smooth { scroll-behavior: smooth; }
.snap-y       { scroll-snap-type: y mandatory; }
.snap-start   { scroll-snap-align: start; }
</style>
