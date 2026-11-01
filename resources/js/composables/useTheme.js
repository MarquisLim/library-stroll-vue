import { ref, watch } from 'vue'

const preference = window.matchMedia('(prefers-color-scheme: dark)')
const savedTheme = () => {
    try { return localStorage.getItem('theme') } catch { return null }
}

const isDark = ref(document.documentElement.dataset.theme === 'dark')

function applyTheme(dark) {
    document.documentElement.dataset.theme = dark ? 'dark' : 'light'
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#17161d' : '#f7f6f2')
}

watch(isDark, applyTheme, { immediate: true })

preference.addEventListener('change', event => {
    if (!['light', 'dark'].includes(savedTheme())) isDark.value = event.matches
})

window.addEventListener('storage', event => {
    if (event.key === 'theme') {
        isDark.value = event.newValue === 'dark' || (event.newValue !== 'light' && preference.matches)
    }
})

export function useTheme() {
    function toggleTheme() {
        isDark.value = !isDark.value
        try { localStorage.setItem('theme', isDark.value ? 'dark' : 'light') } catch { /* Private storage may be unavailable. */ }
    }

    return { isDark, toggleTheme }
}
