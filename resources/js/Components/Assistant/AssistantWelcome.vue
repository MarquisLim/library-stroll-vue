<script setup>
import { ArrowUpRightIcon, BuildingLibraryIcon, GlobeAltIcon, PhotoIcon, SparklesIcon, ViewfinderCircleIcon } from '@heroicons/vue/24/outline'

defineEmits(['choose'])

const examples = [
    { label: 'Птицы у воды', query: 'Найди изображения птиц у воды', icon: ViewfinderCircleIcon },
    { label: 'Горные пейзажи', query: 'Покажи горные пейзажи', icon: PhotoIcon },
    { label: 'Морские сюжеты', query: 'Найди картины с морскими сюжетами', icon: GlobeAltIcon },
    { label: 'Архитектура', query: 'Покажи архитектурные изображения', icon: BuildingLibraryIcon },
]
</script>

<template>
    <section class="mx-auto my-auto w-full max-w-[38rem] py-4 text-center sm:py-8" aria-labelledby="assistant-welcome">
        <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl border border-primary/15 bg-primary/10 text-primary sm:h-[4.25rem] sm:w-[4.25rem]" aria-hidden="true">
            <SparklesIcon class="h-8 w-8" />
        </div>
        <h2 id="assistant-welcome" class="mt-4 text-[1.65rem] font-extrabold leading-tight tracking-tight sm:mt-6 sm:text-4xl">Что хотите найти?</h2>
        <p class="mx-auto mt-3 max-w-lg text-sm leading-relaxed text-base-content/70 sm:text-base">
            Исследуйте медиабиблиотеку с помощью естественного языка. Находите изображения по объектам, темам и визуальным признакам.
        </p>
        <ul class="mt-6 grid grid-cols-2 gap-2 text-left sm:mt-8 sm:gap-3" aria-label="Примеры запросов">
            <li v-for="example in examples" :key="example.query" class="min-w-0">
                <button
                    type="button"
                    class="group flex min-h-[3.25rem] w-full items-center gap-2 rounded-xl border border-base-300 bg-base-100 px-3 py-2.5 text-left transition-colors hover:border-primary/45 hover:text-primary sm:gap-3 sm:px-4"
                    @click="$emit('choose', example.query)"
                >
                    <component :is="example.icon" class="h-4 w-4 shrink-0 text-primary sm:h-5 sm:w-5" aria-hidden="true" />
                    <span class="min-w-0 flex-1 text-xs font-bold leading-snug sm:text-sm">{{ example.label }}</span>
                    <ArrowUpRightIcon class="hidden h-3.5 w-3.5 shrink-0 text-base-content/45 group-hover:text-primary sm:block" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </section>
</template>
