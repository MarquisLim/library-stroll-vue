<template>
    <footer class="bg-base-200 text-base-content border-t border-base-300 lg:pb-0 pb-12">
        <div class="max-w-7xl mx-auto px-6 py-8 grid gap-6 sm:grid-cols-2 md:grid-cols-4">
            <div>
                <h3 class="font-bold mb-2">Навигация</h3>
                <ul class="space-y-1 text-sm">
                    <li><Link class="link-hover" href="/gallery">Галерея</Link></li>
                    <li v-if="$page.props.auth.user"><Link class="link-hover" href="/studio">Студия</Link></li>
                    <li><Link class="link-hover" href="/terms">Польз. соглашение</Link></li>
                    <li><Link class="link-hover" href="/privacy">Политика конфиденц.</Link></li>
                </ul>
            </div>

            <div>
                <h3 class="font-bold mb-2">Юридическое</h3>
                <ul class="space-y-1 text-sm">
                    <li><Link class="link-hover" href="/terms">Польз. соглашение</Link></li>
                    <li><Link class="link-hover" href="/privacy">Политика конфиденц.</Link></li>
                </ul>
            </div>

            <div>
                <h3 class="font-bold mb-2">Контакты</h3>
                <p class="text-sm">1333guesswho1333@gmail.com</p>
            </div>

            <div class="flex flex-col items-center">
                <h3 class="font-bold mb-2">Обратная связь</h3>
                <div class="flex space-x-4">
                    <!-- Telegram SVG -->
                    <a href="https://t.me/Marquis_Lim" target="_blank" aria-label="Telegram" class="hover:text-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 240" class="h-6 w-6 fill-current">
                            <circle cx="120" cy="120" r="120" fill="#37AEE2"/>
                            <path d="M50 120l140-50-20 140L120 140l-40 20z" fill="#fff"/>
                        </svg>
                    </a>
                    <!-- GitHub SVG -->
                    <a href="https://github.com/MarquisLim" target="_blank" aria-label="GitHub" class="hover:text-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-6 w-6 fill-current">
                            <path d="M12 .5C5.73.5.5 5.73.5 12c0 5.08 3.29 9.39 7.86 10.91.57.1.78-.25.78-.55 0-.27-.01-1.17-.02-2.13-3.2.7-3.88-1.54-3.88-1.54-.52-1.34-1.27-1.7-1.27-1.7-1.04-.71.08-.7.08-.7 1.15.08 1.75 1.18 1.75 1.18 1.02 1.75 2.68 1.25 3.34.96.1-.75.4-1.25.72-1.54-2.55-.29-5.24-1.28-5.24-5.7 0-1.26.45-2.29 1.18-3.1-.12-.29-.51-1.46.11-3.04 0 0 .96-.31 3.15 1.18.91-.25 1.88-.38 2.85-.38.97 0 1.94.13 2.85.38 2.18-1.5 3.14-1.18 3.14-1.18.62 1.58.23 2.75.11 3.04.74.81 1.18 1.84 1.18 3.1 0 4.43-2.7 5.4-5.27 5.69.41.35.78 1.04.78 2.1 0 1.52-.01 2.75-.01 3.12 0 .3.21.66.79.55A10.52 10.52 0 0023.5 12c0-6.27-5.23-11.5-11.5-11.5z"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>

        <p class="text-center text-sm opacity-60 pb-4">
            © 2025 LibraryStroll. Все права защищены.
        </p>
    </footer>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
</script>
